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
 * Registers Mailtrap as one of the mailer transports Craft offers in Settings → Email.
 *
 * @author Your Next Agency <developers@yournextagency.com>
 *
 * @since 1.0.0
 */
class Plugin extends \craft\base\Plugin
{
    /**
     * Subscribes to the event Craft fires when it collects mailer transports, and adds the
     * Mailtrap adapter to the list.
     *
     * @inheritdoc
     */
    public function init(): void
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
