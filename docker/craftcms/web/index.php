<?php
/**
 * Craft web bootstrap file
 */

$packageName = getenv('PACKAGE_NAME');

// Define path constants
define('CRAFT_COMPOSER_PATH', dirname(__DIR__).'/composer.json');
define('CRAFT_VENDOR_PATH', dirname(__DIR__).'/vendor');
define('CRAFT_BASE_PATH', dirname(__DIR__)."/packages/$packageName/tests/_craft");
define('CRAFT_CONFIG_PATH', CRAFT_BASE_PATH . '/config');
define('CRAFT_CONTENT_MIGRATIONS_PATH', CRAFT_BASE_PATH . '/migrations');
define('CRAFT_STORAGE_PATH', CRAFT_BASE_PATH . '/storage');
define('CRAFT_TEMPLATES_PATH', CRAFT_BASE_PATH . '/templates');
define('CRAFT_TRANSLATIONS_PATH', CRAFT_BASE_PATH . '/translations');
define('CRAFT_LICENSE_KEY_PATH', CRAFT_CONFIG_PATH.'/license.key');

// Load Composer's autoloader
require_once CRAFT_VENDOR_PATH . '/autoload.php';

// Load dotenv?
if (class_exists('Dotenv\Dotenv') && file_exists(CRAFT_BASE_PATH . '/.env')) {
    Dotenv\Dotenv::create(CRAFT_BASE_PATH)->load();
}

// Define additional PHP constants
// (see https://craftcms.com/docs/3.x/config/#php-constants)
define('CRAFT_ENVIRONMENT', getenv('ENVIRONMENT') ?: 'production');
define('CRAFT_STREAM_LOG', false);
// ...

// Load and run Craft
/** @var craft\web\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/web.php';
$app->run();
