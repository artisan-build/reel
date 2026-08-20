<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient;

use InvalidArgumentException;

final readonly class Envelope
{
    public const int VERSION = 1;

    public const string RECORDER_VERSION = '0.1.0';

    public const string RRWEB_VERSION = '2.1.1';

    public const string COMPRESSION = 'gzip';

    public function __construct(
        public string $applicationId,
        public string $sessionId,
        public string $epochId,
        public int $sequence,
        public string $checksum,
        public int $eventStartedAt,
        public int $eventEndedAt,
        public string $payload,
        public string $grant,
    ) {
        if ($this->sequence < 0 || $this->eventStartedAt < 0 || $this->eventEndedAt < $this->eventStartedAt) {
            throw new InvalidArgumentException('Envelope sequence and event bounds are invalid.');
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $this->checksum)) {
            throw new InvalidArgumentException('Envelope checksum must be a SHA-256 hex digest.');
        }
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'envelope_version' => self::VERSION,
            'recorder_version' => self::RECORDER_VERSION,
            'rrweb_version' => self::RRWEB_VERSION,
            'compression' => self::COMPRESSION,
            'application_id' => $this->applicationId,
            'session_id' => $this->sessionId,
            'epoch_id' => $this->epochId,
            'sequence' => $this->sequence,
            'checksum' => $this->checksum,
            'event_started_at' => $this->eventStartedAt,
            'event_ended_at' => $this->eventEndedAt,
            'payload' => $this->payload,
            'grant' => $this->grant,
        ];
    }
}
