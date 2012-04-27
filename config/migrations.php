<?php defined('SYSPATH') OR die('No direct access allowed.');


// default migrations path
$config['path'] = realpath(dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'migrations');
// development migrations path
$config['development_path'] = $config['path'] . DIRECTORY_SEPARATOR . 'dev';

$config['database_connection_name'] = 'cs_schema';
$config['table_name']    = 'schema_migration';

