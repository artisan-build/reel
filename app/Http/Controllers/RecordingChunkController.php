<?php

namespace App\Http\Controllers;

use App\Exceptions\IngestRejected;
use App\Services\ChunkIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

class RecordingChunkController extends Controller
{
    public function store(Request $request, ChunkIngestor $ingestor): JsonResponse
    {
        $maximumRequestBytes = (int) config('reel_ingest.maximum_request_bytes');
        $contentLength = (int) $request->headers->get('Content-Length', '0');

        if ($contentLength > $maximumRequestBytes) {
            return $this->rejection(new IngestRejected('request_too_large', 413));
        }

        $content = $request->getContent();

        if (strlen($content) > $maximumRequestBytes) {
            return $this->rejection(new IngestRejected('request_too_large', 413));
        }

        try {
            $envelope = json_decode($content, true, 32, JSON_THROW_ON_ERROR);

            if (! is_array($envelope) || array_is_list($envelope)) {
                throw new JsonException('The envelope must be an object.');
            }

            /** @var array<string, mixed> $envelope */
            $result = $ingestor->ingest($envelope, (string) $request->headers->get('Origin'));
        } catch (JsonException) {
            return $this->rejection(new IngestRejected('invalid_json', 422));
        } catch (IngestRejected $rejection) {
            return $this->rejection($rejection);
        }

        return response()->json([
            'accepted' => true,
            'duplicate' => $result->duplicate,
        ], $result->duplicate ? 200 : 202)->header('Access-Control-Allow-Origin', $result->origin);
    }

    private function rejection(IngestRejected $rejection): JsonResponse
    {
        return response()->json([
            'accepted' => false,
            'reason' => $rejection->reason,
        ], $rejection->status);
    }
}
