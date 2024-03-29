<?php

use craft\test\TestSetup;

ini_set('date.timezone', 'UTC');

// Use the test installation of Craft
define('CRAFT_TESTS_PATH', __DIR__);
define('CRAFT_STORAGE_PATH', __DIR__ . '/_craft/storage');
define('CRAFT_TEMPLATES_PATH', __DIR__ . '/_craft/templates');
define('CRAFT_CONFIG_PATH', __DIR__ . '/_craft/config');
define('CRAFT_MIGRATIONS_PATH', __DIR__ . '/_craft/migrations');
define('CRAFT_TRANSLATIONS_PATH', __DIR__ . '/_craft/translations');
define('CRAFT_COMPOSER_PATH', '/app/composer.json');
define('CRAFT_VENDOR_PATH', '/app/vendor');

TestSetup::configureCraft();
