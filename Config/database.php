<?php
/**
 *
 *
 * @link          http://cakephp.org CakePHP(tm) Project
 * @package       app.Config
 * @since         CakePHP(tm) v 0.2.9
 */

/**
 * Database configuration class.
 * You can specify multiple configurations for production, development and testing.
 *
 * datasource => The name of a supported datasource; valid options are as follows:
 *  Database/Mysql - MySQL 4 & 5,
 *  Database/Sqlite - SQLite (PHP5 only),
 *  Database/Postgres - PostgreSQL 7 and higher,
 *  Database/Sqlserver - Microsoft SQL Server 2005 and higher
 *
 * You can add custom database datasources (or override existing datasources) by adding the
 * appropriate file to app/Model/Datasource/Database. Datasources should be named 'MyDatasource.php',
 *
 *
 * persistent => true / false
 * Determines whether or not the database should use a persistent connection
 *
 * host =>
 * the host you connect to the database. To add a socket or port number, use 'port' => #
 *
 * prefix =>
 * Uses the given prefix for all the tables in this database. This setting can be overridden
 * on a per-table basis with the Model::$tablePrefix property.
 *
 * schema =>
 * For Postgres/Sqlserver specifies which schema you would like to use the tables in. Postgres defaults to 'public'. For Sqlserver, it defaults to empty and use
 * the connected user's default schema (typically 'dbo').
 *
 * encoding =>
 * For MySQL, Postgres specifies the character encoding to use when connecting to the
 * database. Uses database default not specified.
 *
 * unix_socket =>
 * For MySQL to connect via socket specify the `unix_socket` parameter instead of `host` and `port`
 *
 * settings =>
 * Array of key/value pairs, on connection it executes SET statements for each pair
 * For MySQL : http://dev.mysql.com/doc/refman/5.6/en/set-statement.html
 * For Postgres : http://www.postgresql.org/docs/9.2/static/sql-set.html
 * For Sql Server : http://msdn.microsoft.com/en-us/library/ms190356.aspx
 *
 * flags =>
 * A key/value array of driver specific connection options.
 */
class DATABASE_CONFIG {

	public $default = array(
			'datasource' => 'Database/Sqlite',
			'persistent' => false,
			'database' => 'data/masterdata.apdb',
			'prefix' => '',
			'encoding' => 'UTF-8',
	);

// 	public $test = array(
// 		'datasource' => 'Database/Mysql',
// 		'persistent' => false,
// 		'host' => 'localhost',
// 		'login' => 'user',
// 		'password' => 'password',
// 		'database' => 'test_database_name',
// 		'prefix' => '',
// 		//'encoding' => 'utf8',
// 	);

	public $aperture = array(
			'datasource' => 'Database/Sqlite',
			'persistent' => false,
			'database' => 'Database/apdb/Library.apdb',
			'prefix' => '',
			'encoding' => 'UTF-8',
	);

	public $apertureProperties = array(
			'datasource' => 'Database/Sqlite',
			'persistent' => false,
			'database' => 'Database/apdb/Properties.apdb',
			'prefix' => '',
			'encoding' => 'UTF-8',
	);


	public $apertureImageProxies = array(
			'datasource' => 'Database/Sqlite',
			'persistent' => false,
			'database' => 'Database/apdb/ImageProxies.apdb',
			'prefix' => '',
			'encoding' => 'UTF-8',
	);
}
