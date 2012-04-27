<?php 

// this will not be used if it does not exist
include_once 'Console/Color.php';

class Migrations
{
	public $migrations_path = null;
	public $dry_run         = false;    // if true, nothing will actually be done, but sql will be output
	
	// output verbosity levels.
	const VERBOSITY_0     = 0;     // no output at all.
	const VERBOSITY_1     = 1;     // output only status messages
	const VERBOSITY_2     = 2;     // output status messages and SQL
	protected $verbosity  = self::VERBOSITY_2;


	/**
	 * Regexp matching valid schema versions.  
	 */
	const SCHEMA_VERSION_REGEX = '(-1|0|\d{14})';

	const SCHEMA_VERSION_FORMAT = '%Y%m%d%H%M%S';
	

	/**
	 * If false, then the schema_migrations table will not be used to track migrations
	 */
	public     $track_migrations = true;
	
	/**
	 * @param string  $migrations_path        path to the migrations directory
	 * @param integer $verbosity             the verbosity level to output at when running
	 * @param boolean $use_schema_version    if false, then the Migrations object will 
	 *                                       be instantiated even if the schema_version 
	 *                                       table is not installed
	 */
	public function __construct($migrations_path = null, $verbosity = null, $track_migrations = true)
	{
		// check to make sure the schema_version table exists.
		if ($track_migrations && !self::is_installed())
		{
			$error_message = "Cannot instantiate new Migrations class.   You must first call Migrations::install() to ensure that the " . Kohana::config('migrations.table_name') . " table exists.";
			throw new Exception($error_message);
		}
		
		$this->track_migrations = $track_migrations;
		
		if (!empty($migrations_path))
		{
			$this->migrations_path = $migrations_path;
		}
		else
		{
			$this->migrations_path =  Kohana::config('migrations.path');
		}
			
		if (isset($verbosity)) 
		{
			$this->verbosity = $verbosity;
		}

		// sync any new migration files to the schema_migration table
		$this->sync_files_to_db();
	}
	
	
	/**
	 * Migrate to a schema version
	 *
	 * Calls each migration step required to get to the schema version of
	 * choice
	 * Outputs a report of the migration
	 *
	 * @param   integer  $to_version  Target schema version
	 * @param   integer  $from_version (optional).  If not specified, the 
	 *                   current_schema_version will be used.
	 * @param   boolean  $output_only_sql     If true, then only executable SQL will be output.  Default false.
	 * @param   boolean  $all_migrations      If true, then all migrations will be run, not just those that have not yet been run. 
	 */
	public function migrate($to_version, $from_version, $output_only_sql = false, $all_migrations = false)
	{		
		try {
			$this->validate_version($to_version);
			$this->validate_version($from_version);
		}
		catch (Exception $e)
		{
			$this->log($e->getMessage());
			return false;
		}

		$direction = ($to_version > $from_version) ? Migration::UP : Migration::DOWN;

		// if we want to try to run ALL migrations, regardless of migrated_at status,
		if ($all_migrations)
		{
			$migrations = $this->load_migrations_between($from_version, $to_version);
		}
		// else only load the migrations between these versions that need to be run.
		else
		{
			// get all of the migrations needing run between these versions
			$migrations = $this->load_migrations_needing_run_between($from_version, $to_version);
		}

		if (empty($migrations))
		{
			$this->log("Nothing to migrate $direction between $from_version and $to_version", self::VERBOSITY_1);
			return true;
		}

		$this->log("Migrating $direction from $from_version to version $to_version", self::VERBOSITY_1);


		if ($output_only_sql)
		{
			$schema_migration_update_sql = "\n--
-- SQL Statements for updating " . Kohana::config('migrations.table_name') . " table:
--\n";
		}

		// loop through each migration and run it
		foreach ($migrations as $migration) 
		{
			// get a SQL string for this migration
			$sql = $this->sql($migration, $direction);
			

			// print a log message and output the SQL as a log message
			// and continue
			if ($output_only_sql)
			{
				// if this is SQL to create a MySQL FUNCTION 
				// and we are outputting the SQL rather than running it, 
				// then wrap the SQL with a DELIMITER. 
				if (strpos($sql, "CREATE FUNCTION") !== false)
				{
					$sql = "DELIMITER ;;;;\n$sql\n;;;;\nDELIMITER ;\n";
				}

				echo $sql;


				// also append SQL to update the schema_migation migrated_at field.  
				// This will be printed out at the end of the loop.
				$database_name = Kohana::config('database.' . Kohana::config('migrations.database_connection_name') . '.connection.database');
				$schema_migration_update_sql .= "USE $database_name;\n";
				$schema_migration_update_sql .= $migration->get_update_migrated_at_sql(($direction == Migration::UP ? true : false)) . "\n\n";
			}
	
			// else, output status and sql and run the migration (if not in dry-run mode)
			else
			{
				$this->log("Migrating $direction through version " . $migration->version . ": '" . $migration->name . "'...", self::VERBOSITY_1);
				$this->log($sql, self::VERBOSITY_2, '%y');
				// if dry run mode, then don't actually do anything.
				if ($this->dry_run)
				{
					$this->log("  In dry run mode, not executing\n", self::VERBOSITY_1, '%p');
				}
				else
				{
					// attempt to run the migration
					$this->run_migration($migration, $direction);
					$this->log("Success!", self::VERBOSITY_1);
				}
			}
		}

		// output the SQL needed to update the schema_migration table now
		if ($output_only_sql)
		{
			echo $schema_migration_update_sql;
		}
		
		return true;
	}

	/**
	 * Returns true if this is a valid migration $version, false if not.
	 * @param integer $version
	 * @throws exception if version does not validate
	 * @return boolean true if version validates.
	 */
	public function validate_version($version)
	{
		// argument checking for $version
		if (!preg_match('/^' . self::SCHEMA_VERSION_REGEX . '/', $version))
		{			
			throw new Exception("Error: cannot migrate to version '$version'. Version must be a timestamp of the form 'YYYYMMDDHHMMSS'.");
		}

		// if ($version < -1 || $version > $this->latest_schema_version())
		// 	throw new Exception("Error: cannot migrate to version '$version'.  $version is not an existing migration version.");
		
		return true;
	}
	
	
	/**
	 * Returns a nicely formatted executable SQL string
	 * of the statements for this migration direction
	 */
	public function sql($migration, $direction)
	{
		$name = $migration->name;
		$version = $migration->version;
		$sql = "
--
-- SQL Statements for migrating $direction through version $version: $name
--";
		$sql .= $migration->sql($direction);
		return $sql;
	}
	
	
	/**
	 * Runs a migration.  If successful, the schema_version table will be updated.
	 * If failure, then this function log an error and exit.
	 * 
	 * @param $migration Migration object
	 * @param string    $direction, either Migration::UP or Migration::DOWN
	 */
	protected function run_migration($migration, $direction)
	{	
		// this shouldn't ever happen
		if ($direction !=  Migration::UP && $direction !=  Migration::DOWN)
		{
			throw new Exception("Invalid Migration direction.  Must be Migration::UP or Migration::DOWN.");
		}

		$version = $migration->version;
		// catch any potential db errors
		// when actually running the migration
		try 
		{
			$sql = $migration->run($direction);
		} 
		catch (Exception $e) 
		{
			throw new Exception("Migrating $direction to version $version '" . $migration->name . "' failed: " . $e->getMessage());
		}
		

		
		// after each migration is run, update the schema_migration table
		// (as long as we are using supposed to use the schema_version table)
		if ($this->track_migrations)
		{
			// if we ran UP, then this is migration is run, if we ran down, then it is not.
			$is_migrated = ($direction == Migration::UP) ? true : false;
			$migration->set_migrated($is_migrated);
		}
	}
	
	
	/**
	 * Loads a migration
	 *
	 * @param   integer   Migration version number
	 * @throws  Exception  If errors are encountered
	 * @return  Migration  Class object
	 */
	public function load_migration($version)
	{
		$f = glob($this->migrations_path . DIRECTORY_SEPARATOR . "${version}_*" . EXT);
		if ( count($f) > 1 ) // Only one migration per step is permitted
		{
			throw new Exception("Multiple migrations have version number $version: " . implode(', ', $f) . '.');
		}

		if ( count($f) == 0 ) // Migration version not found
		{
			throw new Exception("Migration $version not found.");
		}

		$filename = basename($f[0]);
		
		// Filename validations
		$parsed_migration_filename = self::parse_migration_filename($filename);
		if (!$parsed_migration_filename)
		{
			throw new Exception("Invalid migration file name '$file'");
		}
		
		if (!file_exists($f[0]) || !is_readable($f[0]))
		{
			throw new Exception("Cannot load migration file $f[0].  Could not read file $f[0].");
		}
		
		// include the file
		include_once($f[0]);
		$class = $parsed_migration_filename['class'];
		
		if ( !class_exists($class) )
		{
			throw new Exception("Migration class '$class' doesn't exist.");
		}

		$migration_instance = new $class($parsed_migration_filename['version']);
		if (!is_a($migration_instance, 'Migration'))
		{
			throw new Exception("Could not load migration $version. $class is not a subclass of Migration.");
		}
		
		return $migration_instance;
	}



	public function load_migrations($migration_versions = null)
	{
		if (empty($migrations_versions))
		{
			$migrations_versions = $this->get_available_versions();
		}

		$migration_instances = array();
		foreach ($migration_versions as $version) 
		{
			$migration_instances[$version] = $this->load_migration($version);
		}	

		return $migration_instances;
	}




	public function load_migrations_between($from_version, $to_version)
	{
		// assume db is synced, get all versions from db in this range
		$db         = Database::instance(Kohana::config('migrations.database_connection_name'));
		$table_name = Kohana::config('migrations.table_name');
		$field_name = $table_name . '_id';

		// if $from_version < $to_version
		if ($from_version < $to_version)
		{
			$exclusive_from_version = $from_version + 1;	
			$results = $db
				->select($field_name)
				->from($table_name)
				->where("$field_name BETWEEN '$exclusive_from_version' AND '$to_version'")
				->orderby($field_name, 'ASC')
				->get();
		}
		else
		{
			$exclusive_to_version = $to_version + 1;
			$results = $db
				->select($field_name)
				->from($table_name)
				->where("$field_name BETWEEN '$exclusive_to_version' AND '$from_version'")
				->orderby($field_name, 'DESC')
				->get();
		}

		$versions = array();
		foreach ($results as $row) 
		{
			$versions[]= $row->$field_name;
		}

		$migrations = $this->load_migrations($versions);
		return $migrations;
	}

	public function load_migrations_needing_run_between($from_version, $to_version)
	{
		// assume db is synced, get all versions from db in this range
		$db         = Database::instance(Kohana::config('migrations.database_connection_name'));
		$table_name = Kohana::config('migrations.table_name');
		$field_name = $table_name . '_id';

		// if $from_version < $to_version
		if ($from_version < $to_version)
		{
			$exclusive_from_version = $from_version + 1;	
			$results = $db
				->select($field_name)
				->from($table_name)
				->where("$field_name BETWEEN '$exclusive_from_version' AND '$to_version'")
				->where('migrated_at IS NULL')
				->orderby($field_name, 'ASC')
				->get();
		}
		else
		{
			$exclusive_to_version = $to_version + 1;
			$results = $db
				->select($field_name)
				->from($table_name)
				->where("$field_name BETWEEN '$exclusive_to_version' AND '$from_version'")
				->where('migrated_at IS NOT NULL')
				->orderby($field_name, 'DESC')
				->get();
		}

		
		$versions = array();
		foreach ($results as $row) 
		{
			$versions[]= $row->$field_name;
		}

		$migrations = $this->load_migrations($versions);
		return $migrations;
	}



	
	/**
	 * Retrieves latest migration version in the migrations/ directory.
	 *
	 * @return   integer   Latest migration version, or 0 if none found.
	 */
	public function latest_available_schema_version()
	{
		$migration_files = $this->get_migration_files();
		$latest_migration_file = basename(end($migration_files));

		// Calculate the last migration step from existing migrations
		$parsed_migration_filename = self::parse_migration_filename($latest_migration_file);
		if (!$parsed_migration_filename)
		{
			throw new Exception("Invalid migration file name '$latest_migration_file'");
		}
					
		$latest_version = $parsed_migration_filename['version'];
		return $latest_version;
	}
	


	/**
	 * Parses the given $filename for 
	 * version and class name.
	 * 
	 * @param string basename of migration file.
	 * @return array  array with keys 'version', 'name', and 'class'.
	 */
	public static function parse_migration_filename($filename)
	{
		$matches = preg_match('/^' . self::SCHEMA_VERSION_REGEX . '_(\w+)(\.php)?$/', $filename, $match);
		if (!$matches)
		{
			return false;
		}
		else 
		{
			return array(
				'version' => $match[1],
				'name'    => $match[2], 
				'class'   => $match[2]
			);
		}
	}
	
	
		/**
	 * Retrieves the latest schema version that has been migrated up.
	 * @return string
	 */
	public function last_run_schema_version()
	{
		if (!$this->track_migrations)
		{
			return null;
		}

		$db         = Database::instance(Kohana::config('migrations.database_connection_name'));
		$table_name = Kohana::config('migrations.table_name');
		$field_name = $table_name . '_id';
			
		$sql = "SELECT MAX($field_name) as $field_name FROM `$table_name` WHERE migrated_at IS NOT NULL";
		$result = $db->query($sql);
		return $result[0]->$field_name;
	}

	
	
	/**
	 * Returns the last time the schema_version table was updated.
	 * 
	 * @param string $format   String format to pass to strftime.  If null, an integer unixtime stamp is returned
	 * @return timestamp
	 */
	public function last_migration_time($format = null)
	{
		if (!$this->track_migrations)
		{
			return null;
		}
			
		$sql = 'SELECT UNIX_TIMESTAMP(migrated_at) as migrated_at FROM `'  . Kohana::config('migrations.table_name') . '` ORDER BY migrated_at DESC LIMIT 1';
		$db = Database::instance(Kohana::config('migrations.database_connection_name'));
		$result = $db->query($sql);
		$timestamp = $result[0]->migrated_at;
		
		if (empty($timestamp))
		{
			return null;
		}
		
		if (!isset($format))
		{
			return intval($timestamp);
		}
		else
		{
			return strftime($format, $timestamp);
		}
	}
	
	
	/**
	 * Returns a string representing the current status of database Migrations.
	 * If use_schema_version is false, then this will return an empty string.
	 * 
	 * @return string
	 */
	public function status()
	{
		// if 
		if (!$this->track_migrations)
		{
			return '';
		}
		
		$width = 30;
		$string = sprintf("Database Schema Migration Status\n  %-${width}s    %s\n", 
			'Migrations last ran at:', 
			$this->last_migration_time('%Y-%m-%d %T')
		);

		// use include_once here so we don't fail 
		// if this is not available.
		include_once('Console/Table.php');

		// load all migrations into migration instances
		$migrations = $this->load_migrations($this->get_available_versions());
		if (!class_exists('Console_Table'))
		{
			$string .= sprintf("%80s  %14s  %20s\n", 'Migration Name', 'Version', 'migrated_at');
			foreach ($migrations as $migration) 
			{
				$string .= $migration . "\n";
			}
		}
		// else, format the migrations status using console table.
		else
		{
			$table   = new Console_Table();
			$headers = array('Migration Name', 'Version', 'migrated_at');
			$table->setHeaders($headers);

			foreach ($migrations as $migration) 
			{
				$table->addRow(array($migration->name, $migration->version, $migration->migrated_at()));
			}
			$string .= $table->getTable();
		} 

		return $string;
	}
	
	
	/**
	 * Generates a new migration file.  
	 * 
	 * @param string $migration_name
	 */
	public function generate($migration_name)
	{
		$skeleton_migration = 
<<<DOC
<?php

class $migration_name extends Migration
{
	public function up()
	{
		 // throw new RunTimeException('Not Yet Implemented');
	}
	
	public function down()
	{
		// throw new RunTimeException('Not Yet Implemented');
	}
}		
				
DOC
;
		$filename           = $this->get_new_schema_version() . '_' . $migration_name . EXT;
		
		$filepath = $this->migrations_path . DIRECTORY_SEPARATOR . $filename;
		$this->log("Generating new migration file at $filepath.");
		$result = file_put_contents($filepath, $skeleton_migration);
		if (!$result)
		{
			$this->log("Error: Could not generate new migration at $filepath", '%r');
			exit(1);
		}
		
		return $result;
	}



	/**
	 * Returns an array of migration versions that need to be run.
	 * 
	 * @return array
	 */

	public function migrations_needing_run()
	{
		$db = Database::instance(Kohana::config('migrations.database_connection_name'));
		$table_name = Kohana::config('migrations.table_name');
		$field_name = $table_name . '_id';

		$results = $db
			->select($field_name)
			->from($table_name)
			->where('migrated_at IS NULL')
			->get->as_array();
		
		// flatten the array
		$migrations_needing_run = array(); 
		foreach ($results as $row) 
		{
			$migrations_needing_run[]= $row->$field_name;
		}

		return $migrations_needing_run;
	}


	/**
	 * Returns a new schema version string.
	 */
	public function get_new_schema_version()
	{
		return strftime(self::SCHEMA_VERSION_FORMAT);
	}
	
	/**
	 * Reads the list of existent migration files
	 * and creates records for any that do not exist.
	 * 
	 * @return number of new migration files synced to database.
	 */
	public function sync_files_to_db()
	{
		// get the list of migration files
		$new_migration_files = $this->get_new_migration_files();

		// get the list of existent schema_migration records
		$db = Database::instance(Kohana::config('migrations.database_connection_name'));
		$table_name = Kohana::config('migrations.table_name');

		if (!empty($new_migration_files))
		{
			$this->log("Found new migration files.  Syncing to $table_name table...");
		}
		foreach ($new_migration_files as $migration_file) 
		{
			$migration_filename        = basename($migration_file);
			$parsed_migration_filename = self::parse_migration_filename($migration_filename);
			$version = $parsed_migration_filename['version'];
			$name    = $parsed_migration_filename['name'];

			$this->log("Loaded new migration '$name' (version: $version)", self::VERBOSITY_2, '%y');
			$db->set($table_name . '_id', $version)->insert($table_name);
		}

		// now delete any missing migration records
		$this->delete_missing_migration_records();

		return count($new_migration_files);
	}

	/**
	 * looks at the available migration files on disk, and deletes
	 * any record in the schema_migration table that does not
	 * have a matching version on disk.  This allows
	 * Migrations to clean up after themselves, if
	 * someone decides to delete a file.
	 * 
	 * @return number of migration records deleted
	 */
	public function delete_missing_migration_records()
	{
		$migration_versions = $this->get_available_versions();
		// get the list of existent schema_migration records
		$db = Database::instance(Kohana::config('migrations.database_connection_name'));
		$table_name = Kohana::config('migrations.table_name');
		$field_name = $table_name . '_id';

		$sql = "DELETE FROM `$table_name` WHERE $field_name NOT IN (" . implode(',', $migration_versions) . ")";
		$result = $db->query($sql);
		$deleted_count = $result->count();
		if ($deleted_count > 0)
		{
			$this->log('Deleted ' . $deleted_count . ' migration records that did not have corresponding migration files.', self::VERBOSITY_2, '%y');
		}
		return $result->count();
	}

	/**
	 * Compares the migration files on disk to 
	 * the schema_migration records in the database.
	 * 
	 * @return array   of file paths to each migration.
	 */
	public function get_new_migration_files()
	{
			// get the list of migration files
		$migration_files = $this->get_migration_files();

		// get the list of existent schema_migration records
		$db = Database::instance(Kohana::config('migrations.database_connection_name'));
		$table_name = Kohana::config('migrations.table_name');
		$field_name = $table_name . '_id';

		$results = $db
			->select($field_name)
			->from($table_name)
			->orderby($field_name)
			->get()->as_array();


		// flatten the results
		$migration_records = array();
		foreach ($results as $row)
		{
			$migration_records[] = $row->$field_name;
		}

		$new_migration_files = array();
		foreach ($migration_files as $migration_file) 
		{
			$migration_filename        = basename($migration_file);
			$parsed_migration_filename = self::parse_migration_filename($migration_filename);
			$version = $parsed_migration_filename['version'];

			// if this version exists in the $migration_records array, then skip it.
			if (!in_array($version, $migration_records))
			{
				$new_migration_files[]= $migration_file;
			}
		}

		return $new_migration_files;
	}

	public function get_available_versions()
	{
		$migration_files = glob($this->migrations_path . '/*' . EXT);
		$versions = array();
		foreach ($migration_files as $file)
		{
			$filename = basename($file);
			$parsed_migration_filename = self::parse_migration_filename($filename);

			// Mark wrongly formatted files as FALSE for later filtering
			if ($parsed_migration_filename)
			{
				$versions[]= $parsed_migration_filename['version'];
			}

		}

		sort($versions);
		return $versions;
	}



	/**
	 * Returns an array of all migration files on disk.
	 * 
	 * @return array   of file paths to each migration.
	 */
	public function get_migration_files()
	{
		$migration_files = glob($this->migrations_path . '/*' . EXT);
		foreach ($migration_files as $i => $file)
		{
			$filename = basename($file);
			// Mark wrongly formatted files as FALSE for later filtering
			if (!self::parse_migration_filename($filename))
			{
				unset($migration_files[$i]);
			}
		}

		sort($migration_files);
		return $migration_files;
	}
	
	
	/**
	 * Prints a message to stdout if $this->verbosity is greater or equal
	 * to the passed in log level.
	 * 
	 * @param   string  $message  log message
	 * @param   integer $level    a Migrations verbosity level
	 * @param   string  $color (optional) color to pass to Console/Color.php
	 */
	public function log($message, $level = self::VERBOSITY_2, $color = '%g')
	{	
		// don't output anything if the verbosity level is less
		// than this log message's verbosity level
		if ($this->verbosity < $level)	
		{
			return;
		}
		
		// if we've got PEAR's Console::Color, then use it!
		if (class_exists('Console_Color'))
		{
			print Console_Color::convert("${color}$message%n\n");
		}
		else
		{
			echo $message . "\n";
		}
	}
	
	
	/**
	 * True if the schema version table has been installed, false if not
	 * @return boolean
	 */
	public static function is_installed()
	{
		$db = Database::instance(Kohana::config('migrations.database_connection_name'));
		// check to make sure that the database exists.
		if (!$db->database_exists())
		{
			return false;
		}
		
		return $db->table_exists(Kohana::config('migrations.table_name'));
	}

	/**
	 * Creates the schema_version table 
	 * so that migrations can be tracked.
	 * This needs to be done before 
	 * any migrations can be run.
	 */
	public static function install()
	{
		$db       = Database::instance(Kohana::config('migrations.database_connection_name'));
		$database = Kohana::config('database.'.Kohana::config('migrations.database_connection_name').'.connection.database');
		
		// go ahead and try to create the database that the schema version table uses.
		if (!$db->database_exists($database))
		{
			$db->query("CREATE DATABASE IF NOT EXISTS `$database`;");
		}

		$table_name = Kohana::config('migrations.table_name');
		
		// now create the schema_version table
		$sql = 
"CREATE TABLE IF NOT EXISTS `$table_name` (
  `schema_migration_id` varchar(14) NOT NULL COMMENT 'Date format string: YYYYMMDDHHMMSS',
  `migrated_at` timestamp NULL default NULL,
  PRIMARY KEY  (`schema_migration_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;";
		
		$db->query($sql);
	}
}