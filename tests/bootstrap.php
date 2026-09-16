<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) Your Next Agency
 */

require __DIR__.'/../vendor/autoload.php';

// The adapter extends Craft's base model, which reaches for Yii's service locator as soon as
// it is constructed. Loading these two classes is enough: the tests need no database, no
// application instance and no configuration.
require __DIR__.'/../vendor/yiisoft/yii2/Yii.php';
require __DIR__.'/../vendor/craftcms/cms/src/Craft.php';
