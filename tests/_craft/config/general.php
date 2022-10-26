<?php
/**
 * General Configuration
 *
 * All of your system's general configuration settings go in here. You can see a
 * list of the available settings in vendor/craftcms/cms/src/config/GeneralConfig.php.
 *
 * @see \craft\config\GeneralConfig
 */

use craft\helpers\App as AppHelper;

return [

    // The secure key Craft will use for hashing and encrypting data
    'securityKey' => AppHelper::env('SECURITY_KEY'),

    // Whether Dev Mode should be enabled (see https://craftcms.com/guides/what-dev-mode-does)
    'devMode' => true,

    // Whether crawlers should be allowed to index pages and following links
    'disallowRobots' => true,

    // The URI segment that tells Craft to load the control panel
    'cpTrigger' => AppHelper::env('CP_TRIGGER') ?: 'admin',

    // Whether administrative changes should be allowed
    'allowAdminChanges' => true,

    // Override web aliases for improved security
    'aliases' => [
        'web' => AppHelper::env('WEB_URL'),
        'webroot' => "/app/web",
    ],

    // Default Week Start Day (0 = Sunday, 1 = Monday...)
    'defaultWeekStartDay' => 1,

    // Whether generated URLs should omit "index.php"
    'omitScriptNameInUrls' => true,

];
