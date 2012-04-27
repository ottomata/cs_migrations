<?php

/**
* Represents an executable SQL statement
*/
class Statement
{
	/**
	 * @var string $connection  Kohana config database connection name
	 */
	public $connection = null;
	/**
	 * @var string $statement  Executable SQL statement.
	 */
	public $sql  = null;
	
	/**
	 * @var true if execute() has been called on this instance.
	 */
	public $has_executed = false;

	/**
	 * @param string $connection name
	 * @param string $sql
	 */
	function __construct($connection, $sql)
	{
		// TODO: Check to see that $connection is valid
		
		$this->connection = $connection;
		$this->sql  = self::normalize_sql($sql);
	}
	
	/**
	 * Executes the statement
	 * 
	 * @param boolean $force, if true, this statement will be executed 
	 *                        even if it already has been executed once.
	 * @throws Exception if you this statement has already been excuted (and $force != true)
	 */
	public function execute($force = false) 
	{
		if (!$force && $this->has_executed)
			throw new Exception("Cannot execute statement. It has already been executed. '" . $this->__toString() . "'");
			
		$db = Database::instance($this->connection);
		$result = $db->query($this->sql);
		$this->has_executed = true;
		return $result;
	}
	
	public function __toString()
	{
		return $this->sql;
	}
	
	/**
	 * Strips extraneous whitespace and makes sure
	 * the SQL ends in a semicolon.
	 * 
	 * @param string $sql
	 * @return string   the normalized SQL
	 */
	protected static function normalize_sql($sql)
	{
		// trim whitespace from beginning and end
		$normalized_sql = trim($sql);
		// If any newlines are followed by whitespace,
		// keep the newline but strip out the whitespace
		$normalized_sql = preg_replace("/\n\s+/", "\n", $normalized_sql);
		
		// now find any occurances of more than one adjacent whitespace
		// (that is not a newline) and replace it with a single space
		$normalized_sql = preg_replace("/[ \t]+/", ' ', $normalized_sql);
		
		// NOTE:  The previous two regexes could probably be combined...
		// but I'm not sure how, so this is good enough :)
		
		// append a semicolon to the end of the statement if there isn't 
		// already one there.
		if (substr($normalized_sql, strlen($normalized_sql)-1, 1) != ';')
			$normalized_sql .= ';';
		
		return $normalized_sql;
	}
	
	// TODO: implement MySQL Syntax checking.
	// See: http://answers.google.com/answers/threadview/id/730396.html
}
