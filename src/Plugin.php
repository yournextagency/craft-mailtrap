<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) Your Next Agency
 */

namespace yna\mailtrap;

use craft\events\RegisterComponentTypesEvent;
use craft\helpers\MailerHelper;
use yii\base\Event;

/**
 * Mailtrap plugin.
 *
 * @author Your Next Agency <developers@yournextagency.com>
 *
 * @since 1.0.0
 */
class Plugin extends \craft\base\Plugin
{
    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        // Craft 4 named this event differently.
        $legacyEvent = sprintf('%s::EVENT_REGISTER_MAILER_TRANSPORT_TYPES', MailerHelper::class);

        $eventName = defined($legacyEvent)
            ? constant($legacyEvent)
            : MailerHelper::EVENT_REGISTER_MAILER_TRANSPORTS;

        Event::on(
            MailerHelper::class,
            $eventName,
            static function (RegisterComponentTypesEvent $event) {
                $event->types[] = MailtrapAdapter::class;
            }
        );
    }
}
