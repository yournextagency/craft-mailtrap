<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) Your Next Agency
 */

require __DIR__.'/../vendor/autoload.php';

// Two globals Composer's autoloader cannot provide.
//
// Craft's base model reaches for Yii's service locator as soon as one is constructed, so Yii
// is required outright. The Craft class sits outside the craft\ namespace, and the package
// only maps craft\ to src/, so PSR-4 never finds it: nothing in these tests touches it today,
// but any path that does — a translated label, an @alias in a setting — would fail without it.
//
// Nothing else is needed: no database, no application instance, no configuration.
require __DIR__.'/../vendor/yiisoft/yii2/Yii.php';
require __DIR__.'/../vendor/craftcms/cms/src/Craft.php';
