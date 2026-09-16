<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) Your Next Agency
 */

namespace yna\mailtrap\tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use yii\base\InvalidConfigException;
use yna\mailtrap\MailtrapAdapter;

/**
 * Checks how the settings people type in the control panel, or put in their environment,
 * turn into a configured transport.
 *
 * Only defineTransport() is covered here. validate() reaches for Craft::$app through the
 * environment parser behaviour, so form validation needs a booted application and is out of
 * reach of these tests.
 *
 * @author Your Next Agency <developers@yournextagency.com>
 *
 * @since 1.0.0
 */
class MailtrapAdapterTest extends TestCase
{
    /**
     * An endpoint typed without a scheme still selects that host.
     *
     * Values resolved from the environment never pass through defineRules(), so the `url`
     * rule cannot have added the scheme for us.
     *
     * @return void
     */
    public function testBareHostEndpointIsHonoured(): void
    {
        $this->assertSame(
            'mailtrap+api://bulk.api.mailtrap.io',
            $this->dsn(['endpoint' => 'bulk.api.mailtrap.io'])
        );
    }

    /**
     * An endpoint given as a full URL is reduced to its host.
     *
     * @return void
     */
    public function testFullUrlEndpointIsReducedToItsHost(): void
    {
        $this->assertSame(
            'mailtrap+api://bulk.api.mailtrap.io',
            $this->dsn(['endpoint' => 'https://bulk.api.mailtrap.io'])
        );
    }

    /**
     * Stray whitespace around an endpoint is ignored.
     *
     * @return void
     */
    public function testEndpointIsTrimmed(): void
    {
        $this->assertSame(
            'mailtrap+api://bulk.api.mailtrap.io',
            $this->dsn(['endpoint' => "  bulk.api.mailtrap.io\n"])
        );
    }

    /**
     * With no endpoint the transactional host is used.
     *
     * @return void
     */
    public function testEmptyEndpointFallsBackToTheLiveHost(): void
    {
        $this->assertSame('mailtrap+api://send.api.mailtrap.io', $this->dsn([]));
    }

    /**
     * An inbox id switches delivery to the sandbox.
     *
     * @return void
     */
    public function testInboxIdSelectsTheSandbox(): void
    {
        $this->assertSame(
            'mailtrap+sandbox://sandbox.api.mailtrap.io?inboxId=1486708',
            $this->dsn(['inboxId' => '1486708'])
        );
    }

    /**
     * A non-numeric inbox id is refused instead of quietly becoming zero.
     *
     * Casting it with (int) would yield 0, which is not null, so the transport would switch
     * to the sandbox and post production mail to a non-existent inbox.
     *
     * @return void
     */
    public function testNonNumericInboxIdIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('must be a positive whole number');

        $this->dsn(['inboxId' => 'your-inbox-id']);
    }

    /**
     * Zero is not a valid inbox id.
     *
     * @return void
     */
    public function testZeroInboxIdIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);

        $this->dsn(['inboxId' => '0']);
    }

    /**
     * A fractional inbox id is refused.
     *
     * @return void
     */
    public function testFractionalInboxIdIsRefused(): void
    {
        $this->expectException(InvalidConfigException::class);

        $this->dsn(['inboxId' => '12.5']);
    }

    /**
     * Settings stored as environment variables are resolved before use.
     *
     * This is the path the control panel actually stores: project config holds the literal
     * `$MAILTRAP_ENDPOINT`, and the real value only appears at send time.
     *
     * @return void
     */
    public function testEnvironmentVariablesAreResolved(): void
    {
        putenv('MAILTRAP_TEST_ENDPOINT=bulk.api.mailtrap.io');
        putenv('MAILTRAP_TEST_INBOX=1486708');

        try {
            $this->assertSame(
                'mailtrap+sandbox://bulk.api.mailtrap.io?inboxId=1486708',
                $this->dsn([
                    'endpoint' => '$MAILTRAP_TEST_ENDPOINT',
                    'inboxId' => '$MAILTRAP_TEST_INBOX',
                ])
            );
        } finally {
            putenv('MAILTRAP_TEST_ENDPOINT');
            putenv('MAILTRAP_TEST_INBOX');
        }
    }

    /**
     * A broken value in an environment variable is refused just like a typed one.
     *
     * @return void
     */
    public function testBrokenEnvironmentInboxIdIsRefused(): void
    {
        putenv('MAILTRAP_TEST_BROKEN_INBOX=not-a-number');

        try {
            $this->expectException(InvalidConfigException::class);

            $this->dsn(['inboxId' => '$MAILTRAP_TEST_BROKEN_INBOX']);
        } finally {
            putenv('MAILTRAP_TEST_BROKEN_INBOX');
        }
    }

    /**
     * An endpoint neither parser can make sense of is kept rather than quietly replaced.
     *
     * Falling back to the default host would send the mail somewhere the operator never asked
     * for; keeping the value makes the request fail, and the failure names the real cause.
     *
     * @return void
     */
    public function testUnparseableEndpointIsNotSilentlyReplaced(): void
    {
        $dsn = $this->dsn(['endpoint' => '//']);

        $this->assertSame('mailtrap+api:////', $dsn);
        $this->assertStringNotContainsString('send.api.mailtrap.io', $dsn);
    }

    /**
     * Builds an adapter from the given settings and returns the DSN of the transport it makes.
     *
     * @param array<string, string> $settings Settings to override; the token is filled in
     *
     * @return string
     */
    private function dsn(array $settings): string
    {
        $adapter = new MailtrapAdapter();
        $adapter->setAttributes($settings + [
            'apiToken' => 'test-token',
            'endpoint' => '',
            'inboxId' => '',
        ], false);

        $transport = $adapter->defineTransport();

        // The interface also allows a config array; we always build the object ourselves.
        $this->assertInstanceOf(AbstractTransport::class, $transport);

        return (string) $transport;
    }
}
