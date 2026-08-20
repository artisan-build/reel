<?php

namespace App\Services;

use App\Models\RecordingSession;
use DomainException;
use JsonException;

class ReplayManifest
{
    public function __construct(private readonly OperationalCounters $counters) {}

    /** @var list<string> */
    private const array KEYS = [
        'manifest_version',
        'envelope_version',
        'rrweb_version',
        'compression',
        'objects',
        'event_started_at',
        'event_ended_at',
        'epoch_count',
        'chunk_count',
        'gap_count',
        'incomplete',
        'incomplete_reasons',
        'compaction_state',
    ];

    /** @var list<string> */
    private const array OBJECT_KEYS = ['key', 'checksum', 'bytes'];

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public function read(
        array $manifest,
        string $expectedChecksum,
        ?RecordingSession $session = null,
    ): array {
        if (! hash_equals($expectedChecksum, $this->checksum($manifest))) {
            if ($session instanceof RecordingSession) {
                $session->increment('manifest_checksum_failure_count');
            }

            $this->counters->increment('manifest_checksum_failures');
            throw new DomainException('The replay manifest checksum is invalid.');
        }

        $this->validate($manifest);

        return $manifest;
    }

    /** @param array<string, mixed> $manifest */
    public function checksum(array $manifest): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($manifest),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } catch (JsonException $exception) {
            throw new DomainException('The replay manifest cannot be encoded.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $manifest */
    public function validate(array $manifest): void
    {
        if (! $this->hasExactKeys($manifest, self::KEYS)
            || $manifest['manifest_version'] !== 1
            || ! is_int($manifest['envelope_version'])
            || ! is_string($manifest['rrweb_version'])
            || $manifest['rrweb_version'] === ''
            || $manifest['compression'] !== 'gzip'
            || ! is_array($manifest['objects'])
            || ! array_is_list($manifest['objects'])
            || $manifest['objects'] === []
            || ! $this->validBound($manifest['event_started_at'])
            || ! $this->validBound($manifest['event_ended_at'])
            || ! is_int($manifest['epoch_count'])
            || $manifest['epoch_count'] < 0
            || ! is_int($manifest['chunk_count'])
            || $manifest['chunk_count'] < 0
            || ! is_int($manifest['gap_count'])
            || $manifest['gap_count'] < 0
            || ! is_bool($manifest['incomplete'])
            || ! is_array($manifest['incomplete_reasons'])
            || ! array_is_list($manifest['incomplete_reasons'])
            || $manifest['compaction_state'] !== 'ready') {
            throw new DomainException('The replay manifest is invalid.');
        }

        if (($manifest['event_started_at'] === null) !== ($manifest['event_ended_at'] === null)
            || (is_int($manifest['event_started_at'])
                && is_int($manifest['event_ended_at'])
                && $manifest['event_ended_at'] < $manifest['event_started_at'])) {
            throw new DomainException('The replay manifest event bounds are invalid.');
        }

        foreach ($manifest['objects'] as $object) {
            if (! is_array($object)
                || ! $this->hasExactKeys($object, self::OBJECT_KEYS)
                || ! is_string($object['key'])
                || $object['key'] === ''
                || ! is_string($object['checksum'])
                || preg_match('/^[a-f0-9]{64}$/', $object['checksum']) !== 1
                || ! is_int($object['bytes'])
                || $object['bytes'] < 1) {
                throw new DomainException('The replay manifest object is invalid.');
            }
        }

        foreach ($manifest['incomplete_reasons'] as $reason) {
            if (! is_string($reason) || $reason === '') {
                throw new DomainException('The replay manifest incompleteness metadata is invalid.');
            }
        }
    }

    private function validBound(mixed $value): bool
    {
        return $value === null || (is_int($value) && $value >= 0);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $expected
     */
    private function hasExactKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }
}
