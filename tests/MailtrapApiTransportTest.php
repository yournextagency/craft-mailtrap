<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) Your Next Agency
 */

namespace yna\mailtrap\tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use yna\mailtrap\MailtrapApiTransport;

/**
 * Checks that the transport turns a message into the request Mailtrap expects.
 *
 * @author Your Next Agency <developers@yournextagency.com>
 *
 * @since 1.0.0
 */
class MailtrapApiTransportTest extends TestCase
{
    /**
     * Recipients land in separate fields, and an address without a name stays short.
     *
     * @return void
     */
    public function testRecipientsAreSplitByKind(): void
    {
        $email = $this->message()
            ->cc(new Address('cc@example.com', 'Cc Name'))
            ->bcc('bcc@example.com');

        $payload = $this->capture($email)['payload'];

        $this->assertSame(
            ['email' => 'sender@example.com', 'name' => 'Sender Name'],
            $payload['from']
        );
        $this->assertSame([['email' => 'to@example.com', 'name' => 'To Name']], $payload['to']);
        $this->assertSame([['email' => 'cc@example.com', 'name' => 'Cc Name']], $payload['cc']);
        $this->assertSame([['email' => 'bcc@example.com']], $payload['bcc']);
    }

    /**
     * Both the plain text and the HTML body reach the payload.
     *
     * @return void
     */
    public function testBothBodiesArePresent(): void
    {
        $payload = $this->capture($this->message()->html('<p>HTML body</p>'))['payload'];

        $this->assertSame('Subject line', $payload['subject']);
        $this->assertSame('Text body', $payload['text']);
        $this->assertSame('<p>HTML body</p>', $payload['html']);
    }

    /**
     * A tag becomes a category, metadata becomes custom variables, the rest become headers.
     *
     * @return void
     */
    public function testHeadersAreMapped(): void
    {
        $email = $this->message();
        $email->getHeaders()->add(new TagHeader('newsletter'));
        $email->getHeaders()->add(new MetadataHeader('userId', '42'));
        $email->getHeaders()->addTextHeader('X-Custom', 'custom value');

        $payload = $this->capture($email)['payload'];

        $this->assertSame('newsletter', $payload['category']);
        $this->assertSame(['userId' => '42'], $payload['custom_variables']);
        $this->assertSame('custom value', $payload['headers']['X-Custom']);
        $this->assertArrayNotHasKey('Subject', $payload['headers']);
        $this->assertArrayNotHasKey('To', $payload['headers']);
    }

    /**
     * Mailtrap allows one category per message, so a second tag must be refused.
     *
     * @return void
     */
    public function testSecondCategoryIsRejected(): void
    {
        $email = $this->message();
        $email->getHeaders()->add(new TagHeader('first'));
        $email->getHeaders()->add(new TagHeader('second'));

        $this->expectException(TransportException::class);

        $this->capture($email);
    }

    /**
     * Attachments carry a type and a disposition; an inline one also carries a content id.
     *
     * @return void
     */
    public function testAttachmentsAreDescribed(): void
    {
        $email = $this->message()
            ->attach('file contents', 'notes.txt', 'text/plain')
            ->embed('image bytes', 'logo', 'image/png');

        $payload = $this->capture($email)['payload'];

        $this->assertCount(2, $payload['attachments']);

        $file = $payload['attachments'][0];
        $this->assertSame('notes.txt', $file['filename']);
        $this->assertSame('text/plain', $file['type']);
        $this->assertSame('attachment', $file['disposition']);
        $this->assertArrayNotHasKey('content_id', $file);

        $inline = $payload['attachments'][1];
        $this->assertSame('inline', $inline['disposition']);
        $this->assertSame('logo', $inline['content_id']);
    }

    /**
     * Without settings the transport posts to the live host.
     *
     * @return void
     */
    public function testLiveEndpointIsUsedByDefault(): void
    {
        $captured = $this->capture($this->message());

        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://send.api.mailtrap.io/api/send', $captured['url']);
    }

    /**
     * An inbox id switches the host and appends the inbox to the path.
     *
     * @return void
     */
    public function testInboxIdSwitchesToTheSandbox(): void
    {
        $captured = $this->capture($this->message(), 1234567);

        $this->assertSame('https://sandbox.api.mailtrap.io/api/send/1234567', $captured['url']);
    }

    /**
     * An explicitly given host wins over both defaults.
     *
     * @return void
     */
    public function testExplicitHostWins(): void
    {
        $captured = $this->capture($this->message(), null, 'bulk.api.mailtrap.io');

        $this->assertSame('https://bulk.api.mailtrap.io/api/send', $captured['url']);
    }

    /**
     * The token travels as a bearer token, not as a custom header.
     *
     * @return void
     */
    public function testTokenIsSentAsBearer(): void
    {
        $flat = [];
        array_walk_recursive(
            $this->capture($this->message())['headers'],
            static function ($value) use (&$flat): void {
                $flat[] = (string) $value;
            }
        );

        $this->assertStringContainsString('Bearer test-token', implode("\n", $flat));
    }

    /**
     * An attachment with no filename still yields strings, never nulls.
     *
     * @return void
     */
    public function testAttachmentWithoutFilenameIsStillDescribed(): void
    {
        $payload = $this->capture($this->message()->attach('file contents'))['payload'];

        $file = $payload['attachments'][0];

        $this->assertSame('attachment', $file['filename']);
        $this->assertSame('attachment', $file['disposition']);
        $this->assertArrayNotHasKey('content_id', $file);
    }

    /**
     * A nested `errors` payload is flattened instead of collapsing into the word “Array”.
     *
     * @return void
     */
    public function testNestedApiErrorsStayReadable(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse(
                '{"errors":{"from":["is not a verified sender"],"to":["is blocked"]}}',
                ['http_code' => 422]
            );
        });

        $transport = new MailtrapApiTransport('test-token', null, null, $client);

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage(
            'Mailtrap rejected the message: '
            .'"is not a verified sender, is blocked" (status code 422).'
        );

        $transport->send($this->message());
    }

    /**
     * The DSN names the host and, for a sandbox, the inbox behind it.
     *
     * Symfony prints this string in logs, so it is how an operator tells a sandbox run from
     * a live one after the fact.
     *
     * @return void
     */
    public function testDsnDescribesEachMode(): void
    {
        $this->assertSame(
            'mailtrap+api://send.api.mailtrap.io',
            (string) new MailtrapApiTransport('test-token')
        );
        $this->assertSame(
            'mailtrap+sandbox://sandbox.api.mailtrap.io?inboxId=1234567',
            (string) new MailtrapApiTransport('test-token', 1234567)
        );
        $this->assertSame(
            'mailtrap+api://bulk.api.mailtrap.io',
            (string) new MailtrapApiTransport('test-token', null, 'bulk.api.mailtrap.io')
        );
    }

    /**
     * A reply that is not JSON is reported as such instead of as a decoding failure.
     *
     * A proxy or a hosting stub answering with an HTML page is the usual cause, so the
     * message points at the network rather than at the message.
     *
     * @return void
     */
    public function testNonJsonReplyIsReported(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('<html><body>Gateway</body></html>', ['http_code' => 200]);
        });

        $transport = new MailtrapApiTransport('test-token', null, null, $client);

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Mailtrap returned a response that is not JSON.');

        $transport->send($this->message());
    }

    /**
     * A connection that never succeeds is reported as an unreachable server.
     *
     * @return void
     */
    public function testUnreachableServerIsReported(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('', ['error' => 'Connection refused']);
        });

        $transport = new MailtrapApiTransport('test-token', null, null, $client);

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Could not reach the Mailtrap server.');

        $transport->send($this->message());
    }

    /**
     * An inline part that carries its own content id keeps it.
     *
     * The filename is only a fallback, and using it would break an HTML body that refers to
     * the image by its real content id.
     *
     * @return void
     */
    public function testInlineAttachmentKeepsItsOwnContentId(): void
    {
        $part = new DataPart('image bytes', 'logo', 'image/png');
        $part->asInline();

        // symfony/mime 6.0 has no public setter: asking for the id generates and stores one.
        // Reading it here is what makes hasContentId() true across every supported version.
        $contentId = $part->getContentId();

        $email = $this->message();
        $email->addPart($part);

        $payload = $this->capture($email)['payload'];

        $this->assertSame('inline', $payload['attachments'][0]['disposition']);
        $this->assertSame($contentId, $payload['attachments'][0]['content_id']);
        $this->assertNotSame('logo', $payload['attachments'][0]['content_id']);
    }

    /**
     * Sends a message through a fake HTTP client and returns the request it tried to make.
     *
     * @param Email       $email        Message to send through the fake client
     * @param int|null    $inboxId      Sandbox inbox to target, or null for live sending
     * @param string|null $endpointHost Host to override the default with, without a scheme
     *
     * @return array<string, mixed>
     */
    private function capture(
        Email $email,
        ?int $inboxId = null,
        ?string $endpointHost = null
    ): array {
        $captured = [];

        $recorder = static function (
            string $method,
            string $url,
            array $options
        ) use (&$captured): MockResponse {
            $captured = [
                'method' => $method,
                'url' => $url,
                'body' => $options['body'] ?? '',
                'headers' => $options['headers'] ?? [],
            ];

            return new MockResponse('{"success":true}', ['http_code' => 200]);
        };

        $client = new MockHttpClient($recorder);
        $transport = new MailtrapApiTransport('test-token', $inboxId, $endpointHost, $client);
        $transport->send($email);

        $captured['payload'] = json_decode((string) $captured['body'], true);

        return $captured;
    }

    /**
     * Builds a minimal message with a sender and one recipient.
     *
     * @return Email
     */
    private function message(): Email
    {
        return (new Email())
            ->from(new Address('sender@example.com', 'Sender Name'))
            ->to(new Address('to@example.com', 'To Name'))
            ->subject('Subject line')
            ->text('Text body');
    }
}
