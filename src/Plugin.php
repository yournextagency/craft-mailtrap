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

        // Craft 4 and Craft 5 name this event differently, and each version defines only its
        // own constant. Both names are therefore resolved at run time: naming either one
        // directly would be an undefined constant on the other version.
        $legacy = sprintf('%s::EVENT_REGISTER_MAILER_TRANSPORT_TYPES', MailerHelper::class);
        $current = sprintf('%s::EVENT_REGISTER_MAILER_TRANSPORTS', MailerHelper::class);

        $eventName = defined($legacy) ? constant($legacy) : constant($current);

        Event::on(
            MailerHelper::class,
            $eventName,
            static function (RegisterComponentTypesEvent $event) {
                $event->types[] = MailtrapAdapter::class;
            }
        );
    }
}
