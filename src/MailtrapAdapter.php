<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) Your Next Agency
 */

namespace yna\mailtrap;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\helpers\App;
use craft\mail\transportadapters\BaseTransportAdapter;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use yii\base\InvalidConfigException;

/**
 * Plugs the Mailtrap transport into Craft's mailer settings.
 *
 * @author Your Next Agency <developers@yournextagency.com>
 *
 * @since 1.0.0
 */
class MailtrapAdapter extends BaseTransportAdapter
{
    /**
     * @var string API token of the sending domain.
     */
    public string $apiToken = '';

    /**
     * @var string Endpoint URL, or an empty string to pick one automatically.
     */
    public string $endpoint = '';

    /**
     * @var string Sandbox inbox ID, or an empty string to send for real.
     */
    public string $inboxId = '';

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Mailtrap';
    }

    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['parser'] = [
            'class' => EnvAttributeParserBehavior::class,
            'attributes' => ['apiToken', 'endpoint', 'inboxId'],
        ];

        return $behaviors;
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels(): array
    {
        return [
            'apiToken' => Craft::t('mailtrap', 'API Token'),
            'endpoint' => Craft::t('mailtrap', 'Endpoint'),
            'inboxId' => Craft::t('mailtrap', 'Inbox ID'),
        ];
    }

    /**
     * @inheritdoc
     *
     * @return array<int, array<int|string, mixed>>
     */
    public function defineRules(): array
    {
        return [
            [['apiToken'], 'required'],
            [['endpoint'], 'url', 'defaultScheme' => 'https'],
            [['inboxId'], 'integer'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('mailtrap/settings', [
            'adapter' => $this,
        ]);
    }

    /**
     * @inheritdoc
     *
     * @return array<string, mixed>|AbstractTransport
     */
    public function defineTransport(): array|AbstractTransport
    {
        $endpoint = App::parseEnv($this->endpoint);
        $inboxId = App::parseEnv($this->inboxId);

        $host = null;

        if (null !== $endpoint && '' !== $endpoint) {
            $endpoint = trim((string) $endpoint);

            // Without a scheme parse_url() reads the whole value as a path and finds no
            // host, so a bare `bulk.api.mailtrap.io` needs a second attempt.
            $host = parse_url($endpoint, PHP_URL_HOST)
                ?: parse_url('https://'.$endpoint, PHP_URL_HOST)
                ?: $endpoint;
        }

        $inbox = null;

        if (null !== $inboxId && '' !== $inboxId) {
            $inbox = filter_var($inboxId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if (false === $inbox) {
                throw new InvalidConfigException(sprintf(
                    'The Mailtrap “Inbox ID” setting must be a positive whole number, got “%s”.',
                    (string) $inboxId
                ));
            }
        }

        return new MailtrapApiTransport(
            (string) App::parseEnv($this->apiToken),
            $inbox,
            $host
        );
    }
}
