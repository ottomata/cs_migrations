<?php

class test_Statements extends CSTestCase
{
	public $connection    = 'cs_temp';
	public $sql1          = 'CREATE TEMPORARY TABLE test_statements_table (id INT, data VARCHAR(100));';
	public $sql2          = 'ALTER TABLE test_statements_table ADD COLUMN bool BOOLEAN;';

	
	

	public function setUp()
	{
		$this->statements = new Statements();
		
		$this->db = Database::instance($this->connection);
		$this->database_name = Kohana::config('database.cs_temp.connection.database');
		$this->db->query("CREATE DATABASE IF NOT EXISTS " . $this->database_name . ";");
	}
	
	public function tearDown()
	{
		$this->db->query("DROP DATABASE IF EXISTS " . $this->database_name . ";");
	}
	
	
	public function test_toString()
	{
		$this->statements->append($this->connection, $this->sql1);
		$this->statements->append('main', 'SELECT 1 as one');
		$string = $this->statements->__toString();

		// if a database change is necessary, then the returned string
		// will contain a 'USE' statement to switch databases;
		$this->assertRegExp('/USE `.+`;/i', $string);
	}
	
	
	public function test_countable($should_be = 0)
	{
		$this->assertEquals($should_be, count($this->statements));
	}
	
	public function test_append()
	{
		$this->statements->append($this->connection, $this->sql1);
		$this->statements->append($this->connection, $this->sql2);
		$this->test_countable(2);
	}
	
	public function test_execute()
	{
		$this->statements->append($this->connection, $this->sql1);
		$this->statements->append($this->connection, $this->sql2);
		$return = $this->statements->execute();

		$db = Database::instance($this->connection);
		$result = $db->query('DESCRIBE `test_statements_table`;');
		$this->assertEquals($result->count(), 3);  //should be 3 columns (id, data, bool)
		$this->assertEquals($result[0]->Field, 'id');
		$this->assertEquals($result[2]->Field, 'bool');
		
		// assert that the returned string contains expected SQL
		$this->assertRegExp('/CREATE TEMPORARY TABLE/', $return);
		$this->assertRegExp('/ALTER TABLE/', $return);
	}
	

}