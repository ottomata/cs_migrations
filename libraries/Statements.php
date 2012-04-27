<?php defined('SYSPATH') or die('No direct script access.');

/**
* Statements represents a stack of Statement objects
*/
class Statements implements Iterator, Countable
{
	private $statements_array = array();
	private $position = 0;
	
	
	/**
	 * Adds a SQL statement to the list of 
	 * statements to execute
	 */
	public function append($connection, $statement) 
	{
		$this->statements_array[]= new Statement($connection, $statement);
	}
	

	/**
	 * Executes all contained statements.
	 * 
	 * @param boolean   if true, SQL will be output to STDOUT before executing each statement
	 * @return string   the executed SQL.
	 */
	public function execute($output = false)
	{
		$string = '';
		foreach ($this as $statement) 
		{
			$sql_string = $this->get_current_statement_string();
			if ($output) 
				echo $sql_string;
							
			$statement->execute();
			
			$string .= $sql_string;
		}
		
		return $string;
	}
	
	public function __toString()
	{
		$string = '';
		foreach ($this as $statement)
		{
			$string .= $this->get_current_statement_string();
		}
		
		return $string;
	}
	
	/**
	 * Returns a string for this current statement.
	 * If the last statement is for a different
	 * database connection than this one, then 
	 * prepend this statement with 'use `db_name`';
	 */
	private function get_current_statement_string()
	{
		$current_statement  = $this->current();
		$previous_statement = $this->get_previous();
		

		$string = '';
		// if there is a previous statement and its connection is different 
		// than the current one, then prepend a 'use' statement to the SQL.
		if ($this->key() == 0 || ($previous_statement && $previous_statement->connection != $current_statement->connection)) 
		{
			$database_name = Kohana::config('database.' . $current_statement->connection . '.connection.database');
			$string .= "\n-- Statements for database connection " . $current_statement->connection . " \n";
			
			
			// string to include in the sql string if we need to run USE first
			$use_sql = "USE `$database_name`; \n";
			
			// Only add the USE statement before this one
			 // if we are not creating or dropping a database.
			if (preg_match('/^\s*(CREATE|DROP)\s+DATABASE\s+/i', $current_statement, $matches))
			{
				if (strtoupper($matches[1]) == 'CREATE')
					$create_database = true;
			}
			else
				$string .= $use_sql;
				
		}
		$string .= $current_statement . "\n";
		// if we just added SQL to create a database, 
		// then now append the use sql statement
		if (isset($create_database) && $create_database)
			$string .= $use_sql;
		
		return $string;
	}
	
	/**
	 * This class implements iterator, so it has a $position at all times.
	 * This will return the Statement before the Statement at the current position.
	 */
	function get_previous() 
	{
		$previous_position = $this->key() - 1;
		if ($previous_position >= 0 && isset($this->statements_array[$previous_position]))
			return $this->statements_array[$previous_position];
		else
			return false;
	}
	
	
	/**
	 * Iterator methods
	 */
	public function rewind() 
	{
		$this->position = 0;
	}
	
	public function current() 
	{
		return $this->statements_array[$this->position];
	}
	
	public function key() 
	{
		return $this->position;
	}
	
	public function next() 
	{
		++$this->position;
	}
	
	public function valid() 
	{
		return isset($this->statements_array[$this->position]);
	}
	
	/**
	 * Countable: count
	 */
	public function count()
	{
		return count($this->statements_array);
	}
}
