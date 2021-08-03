<?php
/**
 * Database Configuration
 *
 * All of your system's database connection settings go in here. You can see a
 * list of the available settings in vendor/craftcms/cms/src/config/DbConfig.php.
 *
 * @see craft\config\DbConfig
 */

use craft\helpers\App as AppHelper;

$dbDriver = AppHelper::env('DB_DRIVER');

// normalize db driver config setting
if ($dbDriver == 'mariadb') {
    $dbDriver = 'mysql';
} elseif ($dbDriver == 'pg' || $dbDriver == 'postgres' || $dbDriver == 'postgresql') {
    $dbDriver = 'pgsql';
}

return [
    'driver' => $dbDriver,
    'server' => AppHelper::env('DB_HOST'),
    'port' => AppHelper::env('DB_PORT'),
    'user' => AppHelper::env('DB_USER'),
    'password' => AppHelper::env('DB_PASSWORD'),
    'schema' => AppHelper::env('DB_SCHEMA'),
    'database' => AppHelper::env('DB_NAME'),
    'tablePrefix' => AppHelper::env('DB_TABLE_PREFIX'),
];
