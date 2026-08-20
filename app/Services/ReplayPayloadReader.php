<?php

namespace App\Services;

use App\Enums\RecordingSessionStatus;
use App\Exceptions\IngestRejected;
use App\Models\RecordingSession;
use ArtisanBuild\ReelClient\Envelope;
use DomainException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use JsonException;

class ReplayPayloadReader
{
    public function __construct(
        private readonly ReplayManifest $manifestReader,
        private readonly ChunkPrivacyValidator $privacyValidator,
    ) {}

    public function read(RecordingSession $session): ReplayPayload
    {
        if ($session->status !== RecordingSessionStatus::Ready
            || ! is_array($session->manifest)
            || ! is_string($session->manifest_checksum)) {
            return ReplayPayload::diagnostic('replay_not_ready');
        }

        try {
            $manifest = $this->manifestReader->read(
                $session->manifest,
                $session->manifest_checksum,
                $session,
            );
        } catch (DomainException) {
            return ReplayPayload::diagnostic('invalid_manifest');
        }

        if ($manifest['envelope_version'] !== Envelope::VERSION
            || $manifest['rrweb_version'] !== Envelope::RRWEB_VERSION
            || $manifest['compression'] !== Envelope::COMPRESSION) {
            return ReplayPayload::diagnostic('incompatible_version');
        }

        $disk = Storage::disk((string) config('filesystems.default'));
        $events = [];
        $decompressedBytes = 0;

        if (array_sum(array_column($manifest['objects'], 'bytes')) > $session->max_compressed_bytes) {
            return ReplayPayload::diagnostic('invalid_manifest');
        }

        foreach ($manifest['objects'] as $object) {
            if (! is_array($object) || ! $this->objectBelongsToSession($object['key'], $session)) {
                return ReplayPayload::diagnostic('invalid_manifest');
            }

            $decoded = $this->readObject($disk, $object);

            if (is_string($decoded)) {
                $decompressedBytes += strlen($decoded);

                if ($decompressedBytes > (int) config('replay.maximum_decompressed_bytes')) {
                    return ReplayPayload::diagnostic('corrupt_object');
                }

                try {
                    foreach ($this->decodeLines($decoded) as $event) {
                        $events[] = $event;
                    }
                } catch (JsonException) {
                    return ReplayPayload::diagnostic('corrupt_object');
                }

                continue;
            }

            return ReplayPayload::diagnostic($decoded->diagnostic ?? 'corrupt_object');
        }

        try {
            $this->privacyValidator->validate($events);
        } catch (IngestRejected) {
            return ReplayPayload::diagnostic('unsafe_payload');
        }

        return ReplayPayload::ready($events, $manifest);
    }

    /** @param array<string, mixed> $object */
    private function readObject(FilesystemAdapter $disk, array $object): string|ReplayPayload
    {
        $stream = $disk->readStream($object['key']);

        if (! is_resource($stream)) {
            return ReplayPayload::diagnostic('missing_object');
        }

        $temporary = fopen('php://temp/maxmemory:1048576', 'w+b');

        if ($temporary === false) {
            fclose($stream);

            return ReplayPayload::diagnostic('corrupt_object');
        }

        $hash = hash_init('sha256');
        $compressedBytes = 0;
        $compressedLimit = (int) config('replay.maximum_compressed_object_bytes');

        try {
            while (! feof($stream)) {
                $input = fread($stream, 64 * 1024);

                if ($input === false) {
                    fclose($temporary);

                    return ReplayPayload::diagnostic('corrupt_object');
                }

                if ($input === '') {
                    continue;
                }

                $compressedBytes += strlen($input);

                if ($compressedBytes > $compressedLimit
                    || fwrite($temporary, $input) !== strlen($input)) {
                    fclose($temporary);

                    return ReplayPayload::diagnostic('corrupt_object');
                }

                hash_update($hash, $input);
            }
        } finally {
            fclose($stream);
        }

        if ($compressedBytes !== $object['bytes']) {
            fclose($temporary);

            return ReplayPayload::diagnostic('corrupt_object');
        }

        if (! hash_equals($object['checksum'], hash_final($hash))) {
            fclose($temporary);

            return ReplayPayload::diagnostic('checksum_mismatch');
        }

        rewind($temporary);
        $inflater = @inflate_init(ZLIB_ENCODING_GZIP);

        if ($inflater === false) {
            fclose($temporary);

            return ReplayPayload::diagnostic('corrupt_object');
        }

        $decoded = '';
        $decompressedLimit = (int) config('replay.maximum_decompressed_bytes');

        try {
            while (! feof($temporary)) {
                $input = fread($temporary, 64 * 1024);

                if ($input === false) {
                    return ReplayPayload::diagnostic('corrupt_object');
                }

                if ($input === '') {
                    continue;
                }

                $flush = feof($temporary) ? ZLIB_FINISH : ZLIB_SYNC_FLUSH;
                $output = @inflate_add($inflater, $input, $flush);

                if ($output === false || strlen($decoded) + strlen($output) > $decompressedLimit) {
                    return ReplayPayload::diagnostic('corrupt_object');
                }

                $decoded .= $output;
            }
        } finally {
            fclose($temporary);
        }

        if (inflate_get_status($inflater) !== ZLIB_STREAM_END
            || inflate_get_read_len($inflater) !== $compressedBytes) {
            return ReplayPayload::diagnostic('corrupt_object');
        }

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws JsonException
     */
    private function decodeLines(string $decoded): array
    {
        $events = [];

        foreach (preg_split('/\R/', $decoded) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $batch = json_decode($line, true, 64, JSON_THROW_ON_ERROR);

            if (! is_array($batch) || ! array_is_list($batch)) {
                throw new JsonException('The replay object does not contain event batches.');
            }

            foreach ($batch as $event) {
                if (! is_array($event) || array_is_list($event)) {
                    throw new JsonException('The replay object contains an invalid event.');
                }

                $events[] = $event;
            }
        }

        if ($events === []) {
            throw new JsonException('The replay object contains no events.');
        }

        return $events;
    }

    private function objectBelongsToSession(mixed $key, RecordingSession $session): bool
    {
        if (! is_string($key) || str_contains($key, '..')) {
            return false;
        }

        $prefix = implode('/', [
            trim((string) config('reel_ingest.object_prefix'), '/'),
            $session->application->public_id,
            $session->session_id,
        ]).'/';

        return str_starts_with($key, $prefix);
    }
}
