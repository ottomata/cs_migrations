<?php
/**
* Migration is an abstract class that encapsulates 
* two Statements objects.  Each Statements object
* is a collection of Statement objects that is 
* intended to be used to modify database schemas
* either forward or backwards.  
* 
* The subclass must implement the up() and down()
* methods.  In each of these methods, the 
* subclass should call the statement() method to
* add statements to the corresponding direction.
*/
abstract class Migration
{
	// @var string $name  Arbitrary migration name, this is the same as the subclass type.
	public $name             = null;

	/**
	 * Migration version number, this is passed to the constructor 
	 * and should be inferred from the subclass's filename.
	 */
	public $version			 = null;

		
	// Statements objects that are populated by the implemented up() and down() methods.
	private $up_statements   = null;
	private $down_statements = null;
	
	// up and down constants
	const UP   = 'up';
	const DOWN = 'down';
	
	/**
	 * Adds statements to $up_statements
	 */
	abstract public function up();
	
	/**
	 * Adds statements to $down_statements
	 */
	abstract public function down();
	
	public function __construct($version)
	{
		$this->name            = get_class($this);
		$this->version         = $version;

		$this->up_statements   = new Statements();
		$this->down_statements = new Statements();

		// call up and down to populate the up and down Statements
		// Note that this does not actually run the up and down statements, 
		// but loads the $up_statements and $down_statements properties
		// with the sql Statements for each direction.
		$this->up();
		$this->down();
	}
	
	/**
	 * Adds a statement to a particular direction.
	 * You may only call this from the up() or down()
	 * methods.
	 * 
	 * @param string $connection
	 * @param string $sql
	 * @throws Exception if not called from up() or down() method.
	 */
	public function statement($connection, $sql)
	{
		// Get the Statements object to append to
		$statements = $this->get_statements_object($connection);		
		$statements->append($connection, $sql);
	}
	
	/**
	 * Appends a 'CREATE DATABASE ...' statement.
	 * The database created will be the one in the
	 * database configs for $connection.
	 * You may only call this from the up() or down()
	 * methods.
	 * 
	 * @param string $connection
	 * @throws Exception if not called from up() or down() method.
	 */
	public function create_database($connection)
	{
		// Get the Statements object to append to		
		$statements = $this->get_statements_object($connection);		
		$database_name = Kohana::config("database.$connection.connection.database");
		$sql = "CREATE DATABASE IF NOT EXISTS `$database_name`;";
		$statements->append($connection, $sql);
	}
	
	/**
	 * Appends a 'DROP DATABASE ...' statement.
	 * The database created will be the one in the
	 * database configs for $connection.
	 * You may only call this from the up() or down()
	 * methods.
	 * 
	 * @param string $connection
	 * @throws Exception if not called from up() or down() method.
	 */
	public function drop_database($connection)
	{
		// Get the Statements object to append to
		$statements = $this->get_statements_object($connection);					
		$database_name = Kohana::config("database.$connection.connection.database");
		$sql = "DROP DATABASE IF EXISTS `$database_name`;";
		$statements->append($connection, $sql);
	}
	
	/**
	 * Appends a statement for the SQL found in $filepath.
	 * You may only call this from the up() or down()
	 * methods.
	 * 
	 * @param string $connection
	 * @param string $sql
	 * @throws Exception if not called from up() or down() method.
	 */
	public function statement_from_file($connection, $filepath)
	{
		// Get the Statements object to append to
		$statements = $this->get_statements_object($connection);					
		$statements->append($connection, file_get_contents($filepath));
	}
	
	/**
	 * This function does several things.
	 * 1.  It checks that the caller of this function's
	 *     caller is either up() or down().
	 * 2.  It checks that $connection is a valid database connection.
	 * If either 1 or 2 fail, an Exception is thrown.
	 * 3.  Returns the Statements object for this Migration that is
	 *     appropriate for adding Statement objects to (based on up() or down())
	 * 
	 * @return Statements object
	 */
	protected function get_statements_object($connection)
	{
		// get the name of the function that called 
		// the function that called check_method_caller
		$backtrace = debug_backtrace();

		// find the name of the calling method.
		foreach ($backtrace as $i => $call) 
		{
			// echo "$i $call[function] $call[class] $call[file]\n" ;
			if ($call['file'] == __FILE__ && in_array($call['function'], array(self::UP, self::DOWN)))
			{
				$calling_method_index = $i;
				$calling_method_name = $call['function'];
				break;
			}
		}

	 	// $calling_method_name = $backtrace[2]['function'];

		// If we were called from up() or down(), then all is well.
		if (!($calling_method_name == 'up' || $calling_method_name == 'down'))
		{
			throw new Exception("Can only call " . $backtrace[1]['function'] . "() from an implemented up() or down() method.");
		}
		
		// make sure that $connection is valid database
		// connection defined in database.php.
		$database_connections = Kohana::config('database');
		if (!array_key_exists($connection, $database_connections))
		{
			throw new Exception("Cannot add statement: '$connection' is not a valid database connection.");
		}
		
		$statements = $calling_method_name . '_statements';
		return $this->$statements;
	}
	


	
	/**
	 * Executes SQL Statements for a given direction.
	 * 
	 * @param string $direction  Either self::UP or self::DOWN, default is UP
	 * @param boolean $output   if true, SQL will be output to STDOUT while executing.
	 * 
	 * @throws Exception if $direction is not self::UP or self::DOWN
	 * @return string $sql that was executed
	 * 
	 */
	public function run($direction = self::UP, $output = false) 
	{
		self::validate_direction($direction);

		$property = "${direction}_statements";
		$statements = $this->$property;
		
		$result = $statements->execute($output);
		return $result;
	}
	
	
	/**
	 * Returns a sql string for this migration direction
	 * @param string $direction  Either self::UP or self::DOWN, default is UP
	 * @throws Exception if $direction is not self::UP or self::DOWN
	 */
	public function sql($direction = self::UP)
	{
		self::validate_direction($direction);
		
		$property = "${direction}_statements";
		return $this->$property->__toString();
	}
	

	/**
	 * @param string $direction
	 * @throws Exception if $direction is not valid
	 * @returns true if valid, false if not.
	 */
	private static function validate_direction($direction)
	{
		if ($direction != self::UP && $direction != self::DOWN) 
		{
			throw new Exception("Invalid migration direction '$direction'. Must either be Migration::UP or Migration::DOWN.");
			return false;
		}
		else 
		{
			return true;
		}
	}

	public function is_migrated()
	{
		return (boolean)($this->migrated_at());
	}

	public function migrated_at()
	{
		$db         = Database::instance(Kohana::config('migrations.database_connection_name'));
		$table_name = Kohana::config('migrations.table_name');
		$field_name = $table_name . '_id';

		$result = $db
			->select('migrated_at')
			->from($table_name)
			->where($field_name, $this->version)
			->get();

		return $result[0]->migrated_at;
	}

	public function get_update_migrated_at_sql($is_migrated)
	{
		$table_name    = Kohana::config('migrations.table_name');
		$field_name    = $table_name . '_id';	

		$migrated_at = $is_migrated ? "'" . date('Y-m-d H:m:s') . "'" : 'NULL';

		$sql = "UPDATE $table_name SET migrated_at = $migrated_at WHERE $field_name = '" . $this->version . "';";
		return $sql;
	}


	public function set_migrated($is_migrated = true)
	{
		$db     = Database::instance(Kohana::config('migrations.database_connection_name'));
		$result = $db->query($this->get_update_migrated_at_sql($is_migrated));
		
		if (!$result)
		{
			throw new CSException("Could not set migrated_at to " . $migrated_at . " for migration " . $this->version);
		}

		return $result;
	}

	public function __toString()
	{
		$migrated_at = $this->migrated_at();
		// $migrated_at = ($migrated_at) ? $migrated_at : 'never';
		return sprintf("%80s  %14s  %20s", $this->name, $this->version, $migrated_at); 
	}


}


