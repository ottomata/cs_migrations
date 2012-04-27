This code was written by Andrew Otto for CouchSurfing.org and the Kohana 2 PHP
framework.  It has been extracted from CouchSurfing's codebase and put here for
reference.  This code will not work by itself, but could be adapted into a more
general PHP MySQL migrations library.


# What are Migrations?
Migrations are a way to keep database schema changes in a version control 
system.  Often, migrations are coded in a database-agnostic manner.  Meaning 
that the DDL statements are usually abstracted so that any database schema may
be recreated on any RDBMS.  

This implementation does not do this.  Each migration file specifies 
the exact MySQL statements that need to be run in order for the schema to be 
brought up to date.  By default, migration files are stored in 
modules/database/migrations.  These files are ordered by timestamp.  
The file name must match the class name inside, and each migration 
class must extend class Migration.  Migration is an abstract class 
representing one migration.  It has two abstract methods:  the 'up' method and 
the 'down' method.  Your migration subclass must implement both of these 
methods.  The 'up' method is used for running migrations forward, and 'down' 
is used to migrate backwards.  

# Example
Example File: 20120104192233_CreateMyTable.php

    class AlterMyTable extends Migration
    {
      public function up()
      {
        $this->statement('main', 
          'ALATER TABLE `my_table` 
           ADD COLUMN `newcol` INT, data VARCHAR(100);'
        );
      }
      
      public function down()
      {
        $this->statement('main', 'ALTER TABLE `my_table` DROP COLUMN `newcol`;');
      }
    }

This migrations 'up' method adds a statement to alter the table 'my_table' on 
the database connection 'main'.  The Migration#statement() method is the 
really important one here.  It is how you indicate what statements need to be 
run for a migration.  You may call $this->statement() as many times as you 
need to.  Statements will be run in the order that they are added.  

The migrate script (located in bin/migrate) can generate skeleton migration 
files for you.  See the help info by running 'migrate --help'.

h1. Schema Migration
The schema_migration table is used to keep track of which schema version your 
database is currently at.  When you update your codebase, you could possibly 
then get a new migration file from another developer.  When you run migrate,
the code will notice that there is a new migration file and add a record
to the schema_migration table.  If you tell it to do so, the migrate
script will automatically run this new migration's up() method.
See the migrate script's help documentation by running 'migrate --help'.

# Use Case 1
Doodeedoo, here I am, a happy developer working on code.  I'll be a good 
developer and run svn update before continuing to work.  Oh!  Look!  Someone 
checked in a new migration!  I will run the migrate script to make sure my 
database is up to date as well:

    $ bin/migrate
    Migrating up to version 20120104192233
    Migrating up through schema version 20120104192233: 'create_boogers_table'...
    
    --
    -- SQL Statements for migrating up through schema version 20120104192233: create_boogers_table
    --
    -- Statements for database connection main 
    USE `cs_core`; 
    CREATE TABLE boogers (id INT, data VARCHAR(100));
    
    Success!

Yay! Look now I have that new boogers table.  That boogers table was the best 
idea ever.  Great.  Now let me get back to coding.

2 hours later...

ACK! ACK!  That boogers table needs an index!  Doh!  It will never run in 
production like that!  First, I will generate a new migration:

  $ bin/migrate generate boogers_add_data_index
  Generating new migration file at .../modules/migration/migrations/20120104212233_boogers_add_data_index.php.

Now I will go edit that file and add the index.  Oh yeah, and I can't forget
to add proper instructions on how to migrate back down as well.  Ah, there
we go, now it looks like this:

    class boogers_add_data_index extends Migration
    {
    	public function up()
    	{
    		$this->statement('main', "ALTER TABLE `booger` ADD INDEX `data` (data);");
    	}
  	
    	public function down()
    	{
    		$this->statement('main', "ALTER TABLE `booger` DROP INDEX `data`;");
    	}
    }		

Beautiful.  Now I can run the migrate script to get the index, and check this 
file in so that other developers can get the index on their databases as well.

# Use Case 2: Database Creation
Very rarely you will have to create or drop an actual database.  Migrations 
supports this, but it is best if you do not use the statement() method to run 
direct CREATE and DROP DATABASE statements.  Instead, use the methods 
create_database() and drop_database().  To create a new database:

    class create_cs_yay_database extends Migration
    {
    	public function up()
    	{
    		$this->create_database('cs_yay');
    	}
  	
    	public function down()
    	{
    		$this->drop_database('cs_yay');
    	}
    }		

Notice that neither of these methods actually take any SQL or a database name.  
The database name to create or dropped will be inferred from the database 
configs for the 'cs_yay' connection.  Note that this requires that the 'cs_yay' 
connection is actually defined in the config/database.php file.

#. But what about production databases?
Migrations are not intended to be run directly on anything but the developer 
databases.  They do two important things.
- Keep track of schema changes.
- Automatically sync these schema changes to developer databases.

If you are a sysadmin looking for a way to run a migration on a real production 
database, then you should use the migrate tool to print out the migrations that 
you need.

    bin/migrate --output

This will print out only the migration SQL suitable for running on a MySQL 
instance.  You may save this output in a file, or copy and paste it to run it.
