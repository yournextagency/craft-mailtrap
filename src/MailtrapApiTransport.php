<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) Your Next Agency
 */

namespace yna\mailtrap;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends mail through the Mailtrap Email API.
 *
 * @author Your Next Agency <developers@yournextagency.com>
 *
 * @since 1.0.0
 */
class MailtrapApiTransport extends AbstractApiTransport
{
    /**
     * @var string Host of the transactional sending stream.
     */
    public const HOST_LIVE = 'send.api.mailtrap.io';

    /**
     * @var string Host of the sandbox stream, used when an inbox ID is set.
     */
    public const HOST_SANDBOX = 'sandbox.api.mailtrap.io';

    /**
     * @var string[] Headers that already have a dedicated field in the payload.
     */
    private const HEADERS_TO_BYPASS = [
        'from',
        'to',
        'cc',
        'bcc',
        'subject',
        'content-type',
        'sender',
    ];

    /**
     * @param string                        $token        API token of the sending domain
     * @param int|null                      $inboxId      Sandbox inbox to deliver into, or null
     * @param string|null                   $endpointHost Host without a scheme, or null
     * @param HttpClientInterface|null      $client       Sender client, built by Symfony if null
     * @param EventDispatcherInterface|null $dispatcher   Passed to Symfony; fires transport events
     * @param LoggerInterface|null          $logger       Passed to Symfony for transport logging
     */
    public function __construct(
        #[\SensitiveParameter] private string $token,
        private ?int $inboxId = null,
        private ?string $endpointHost = null,
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct($client, $dispatcher, $logger);
    }

    /**
     * Returns the DSN Symfony prints in logs and error messages; it is never used to send.
     *
     * @return string
     */
    public function __toString(): string
    {
        if ($this->inboxId !== null) {
            return sprintf(
                'mailtrap+sandbox://%s?inboxId=%d',
                $this->resolveHost(),
                $this->inboxId
            );
        }

        return sprintf('mailtrap+api://%s', $this->resolveHost());
    }

    /**
     * Sends one message through the Mailtrap API.
     *
     * @param SentMessage $sentMessage Symfony's wrapper around the message being sent
     * @param Email       $email       The message itself: subject, bodies, recipients, attachments
     * @param Envelope    $envelope    Who the message is really sent from and to
     *
     * @return ResponseInterface
     */
    protected function doSendApi(
        SentMessage $sentMessage,
        Email $email,
        Envelope $envelope
    ): ResponseInterface {
        $response = $this->client->request('POST', $this->getEndpoint(), [
            'json' => $this->getPayload($email, $envelope),
            'auth_bearer' => $this->token,
        ]);

        try {
            $statusCode = $response->getStatusCode();
            $result = $response->toArray(false);
        } catch (DecodingExceptionInterface $e) {
            throw new HttpTransportException(
                'Mailtrap returned a response that is not JSON.',
                $response,
                0,
                $e
            );
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException(
                'Could not reach the Mailtrap server.',
                $response,
                0,
                $e
            );
        }

        if (200 !== $statusCode) {
            throw new HttpTransportException(
                sprintf(
                    'Mailtrap rejected the message: "%s" (status code %d).',
                    self::describeErrors($result['errors'] ?? null),
                    $statusCode
                ),
                $response
            );
        }

        return $response;
    }

    /**
     * Returns the host to send through: an explicitly configured one always wins, otherwise
     * an inbox id selects the sandbox and its absence the transactional stream.
     *
     * @return string
     */
    private function resolveHost(): string
    {
        if ($this->endpointHost !== null && $this->endpointHost !== '') {
            return $this->endpointHost;
        }

        return $this->inboxId !== null ? self::HOST_SANDBOX : self::HOST_LIVE;
    }

    /**
     * Returns the full URL to POST the message to, with the inbox id appended to the path
     * when delivering into a sandbox.
     *
     * @return string
     */
    private function getEndpoint(): string
    {
        $path = '/api/send';

        if ($this->inboxId !== null) {
            $path .= '/'.$this->inboxId;
        }

        return 'https://'.$this->resolveHost().$path;
    }

    /**
     * Builds the request body out of the message.
     *
     * @param Email    $email    The message itself: subject, bodies, recipients, attachments
     * @param Envelope $envelope Who the message is really sent from and to
     *
     * @return array<string, mixed>
     */
    private function getPayload(Email $email, Envelope $envelope): array
    {
        $payload = [
            'from' => self::encodeAddress($envelope->getSender()),
            'to' => self::encodeAddresses($email->getTo()),
            'cc' => self::encodeAddresses($email->getCc()),
            'bcc' => self::encodeAddresses($email->getBcc()),
            'subject' => $email->getSubject(),
            'text' => $email->getTextBody(),
            'html' => $email->getHtmlBody(),
            'attachments' => $this->getAttachments($email),
        ];

        foreach ($email->getHeaders()->all() as $name => $header) {
            if (in_array($name, self::HEADERS_TO_BYPASS, true)) {
                continue;
            }

            if ($header instanceof TagHeader) {
                if (isset($payload['category'])) {
                    throw new TransportException('Mailtrap allows only one category per email.');
                }

                $payload['category'] = $header->getValue();
                continue;
            }

            if ($header instanceof MetadataHeader) {
                $payload['custom_variables'][$header->getKey()] = $header->getValue();
                continue;
            }

            $payload['headers'][$header->getName()] = $header->getBodyAsString();
        }

        return $payload;
    }

    /**
     * Converts a list of addresses into Mailtrap's format.
     *
     * @param Address[] $addresses Addresses to convert, keeping the order of the message
     *
     * @return array<int, array<string, string>>
     */
    private static function encodeAddresses(array $addresses): array
    {
        return array_map([self::class, 'encodeAddress'], $addresses);
    }

    /**
     * Converts one address into Mailtrap's format.
     *
     * @param Address $address Address to convert; an empty display name is left out
     *
     * @return array<string, string>
     */
    private static function encodeAddress(Address $address): array
    {
        return array_filter([
            'email' => $address->getEncodedAddress(),
            'name' => $address->getName(),
        ]);
    }

    /**
     * Flattens whatever Mailtrap put in the `errors` field into one readable line.
     *
     * @param mixed $errors Value of the `errors` field, of a shape the API does not guarantee
     *
     * @return string
     */
    private static function describeErrors($errors): string
    {
        $values = is_array($errors) ? $errors : [$errors];
        $flat = [];

        array_walk_recursive(
            $values,
            static function ($value) use (&$flat): void {
                if (null !== $value && '' !== $value) {
                    $flat[] = (string) $value;
                }
            }
        );

        return [] === $flat ? 'no error message' : implode(', ', $flat);
    }

    /**
     * Converts the message attachments into Mailtrap's format.
     *
     * The content id falls back to the filename, which is what an HTML body built with
     * Email::embed() refers to: an API transport sends the HTML as it stands, without the
     * cid rewriting Symfony does when it assembles a MIME message. On symfony/mime 6.0 that
     * fallback is the only possible outcome, because getAttachments() rebuilds every part on
     * each call and hasContentId() can never be true there.
     *
     * @param Email $email The message itself: subject, bodies, recipients, attachments
     *
     * @return array<int, array<string, string>>
     */
    private function getAttachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename');
            $disposition = $headers->getHeaderBody('Content-Disposition');
            $contentType = $headers->get('Content-Type');

            $type = $contentType !== null
                ? $contentType->getBody()
                : 'application/octet-stream';

            $item = [
                'content' => $attachment->bodyToString(),
                'type' => $type,
                'filename' => is_string($filename) && '' !== $filename
                    ? $filename
                    : 'attachment',
                'disposition' => is_string($disposition) && '' !== $disposition
                    ? $disposition
                    : 'attachment',
            ];

            if ('inline' === $item['disposition']) {
                $item['content_id'] = $attachment->hasContentId()
                    ? $attachment->getContentId()
                    : $item['filename'];
            }

            $attachments[] = $item;
        }

        return $attachments;
    }
}
