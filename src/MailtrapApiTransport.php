<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) Your Next Agency
 */

namespace yna\mailtrap;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
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
     * @param string                        $token      API token of the sending domain
     * @param int|null                      $inboxId    Sandbox inbox to deliver into, or null
     * @param string|null                   $host       Host without a scheme, or null to autodetect
     * @param HttpClientInterface|null      $client
     * @param EventDispatcherInterface|null $dispatcher
     * @param LoggerInterface|null          $logger
     */
    public function __construct(
        #[\SensitiveParameter] private string $token,
        private ?int $inboxId = null,
        private ?string $host = null,
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct($client, $dispatcher, $logger);
    }

    /**
     * @inheritdoc
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
        return $this->client->request('POST', $this->getEndpoint(), [
            'json' => $this->getPayload($email, $envelope),
            'auth_bearer' => $this->token,
        ]);
    }

    /**
     * Returns the host to send through.
     *
     * @return string
     */
    private function resolveHost(): string
    {
        if ($this->host !== null && $this->host !== '') {
            return $this->host;
        }

        return $this->inboxId !== null ? self::HOST_SANDBOX : self::HOST_LIVE;
    }

    /**
     * Returns the full URL to POST the message to.
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
     * @return array
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
     * @param Address[] $addresses
     *
     * @return array[]
     */
    private static function encodeAddresses(array $addresses): array
    {
        return array_map([self::class, 'encodeAddress'], $addresses);
    }

    /**
     * Converts one address into Mailtrap's format.
     *
     * @param Address $address
     *
     * @return array
     */
    private static function encodeAddress(Address $address): array
    {
        return array_filter([
            'email' => $address->getEncodedAddress(),
            'name' => $address->getName(),
        ]);
    }

    /**
     * Converts the message attachments into Mailtrap's format.
     *
     * @param Email $email The message itself: subject, bodies, recipients, attachments
     *
     * @return array[]
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
                'filename' => $filename,
                'disposition' => $disposition,
            ];

            if ('inline' === $disposition) {
                $item['content_id'] = $attachment->hasContentId()
                    ? $attachment->getContentId()
                    : $filename;
            }

            $attachments[] = $item;
        }

        return $attachments;
    }
}
