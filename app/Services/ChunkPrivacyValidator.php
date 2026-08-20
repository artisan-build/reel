<?php

namespace App\Services;

use App\Exceptions\IngestRejected;

class ChunkPrivacyValidator
{
    /** @var list<string> */
    private const array HYDRATION_ATTRIBUTES = ['data-page', 'wire:snapshot', 'wire:initial-data'];

    /** @var list<string> */
    private const array RESOURCE_ATTRIBUTES = [
        'href', 'url', 'src', 'srcset', 'action', 'formaction', 'poster', 'data', 'cite', 'background',
        'xlink:href', 'srcdoc',
    ];

    /** @var list<string> */
    private const array BLOCKED_TAGS = [
        'canvas', 'video', 'audio', 'iframe', 'object', 'embed', 'source', 'track',
    ];

    /** @var list<string> */
    private const array SENSITIVE_KEYS = [
        'cookie', 'cookies', 'localstorage', 'sessionstorage', 'storagevalue', 'authorization',
        'requestbody', 'responsebody', 'request_body', 'response_body', 'consolearguments',
        'console_arguments',
    ];

    public function validate(mixed $events): void
    {
        if (! is_array($events) || ! array_is_list($events)) {
            $this->reject('invalid_event_batch');
        }

        foreach ($events as $event) {
            if (! is_array($event)) {
                $this->reject('invalid_event');
            }

            $this->validateEvent($event);
        }
    }

    /** @param array<mixed> $event */
    private function validateEvent(array $event): void
    {
        $type = $event['type'] ?? null;
        $data = $event['data'] ?? null;

        if (! is_int($type) || ! is_array($data)) {
            $this->reject('invalid_event');
        }

        if ($type === 4 && isset($data['href'])) {
            $this->assertSanitizedMetadataUrl($data['href']);
            unset($data['href']);
        }

        if ($type === 2 && isset($data['node'])) {
            $this->validateNode($data['node'], false);
            unset($data['node']);
        }

        if ($type === 3) {
            $this->validateIncrementalEvent($data);
        }

        if ($type === 6 || $this->isConsoleEvent($data)) {
            $this->assertNoConsoleArguments($data);
        }

        $this->scan($data);
    }

    /** @param array<mixed> $data */
    private function validateIncrementalEvent(array &$data): void
    {
        if (isset($data['href'])) {
            $this->assertSanitizedMetadataUrl($data['href']);
            unset($data['href']);
        }

        if (isset($data['adds']) && is_array($data['adds'])) {
            foreach ($data['adds'] as $addition) {
                if (is_array($addition) && isset($addition['node'])) {
                    $this->validateNode($addition['node'], false);
                }
            }
            unset($data['adds']);
        }

        if (isset($data['attributes']) && is_array($data['attributes'])) {
            foreach ($data['attributes'] as $mutation) {
                if (is_array($mutation) && isset($mutation['attributes'])) {
                    // Out-of-order mutations carry a node id but no trustworthy tag ancestry.
                    $this->validateAttributes($mutation['attributes'], true);
                }
            }
            unset($data['attributes']);
        }

        if (($data['source'] ?? null) === 5
            && array_key_exists('text', $data)
            && $data['text'] !== '***') {
            $this->reject('unmasked_form_value');
        }

        if (in_array($data['source'] ?? null, [8, 13, 15], true)) {
            $this->assertNoCssUrls($data);
        }
    }

    private function validateNode(mixed $node, bool $masked): void
    {
        if (! is_array($node)) {
            $this->reject('invalid_node');
        }

        if (($node['type'] ?? null) === 2) {
            $tag = strtolower((string) ($node['tagName'] ?? ''));
            $attributes = $node['attributes'] ?? [];

            if (! is_array($attributes)) {
                $this->reject('invalid_node_attributes');
            }

            if (in_array($tag, self::BLOCKED_TAGS, true)) {
                $this->reject('blocked_media_element');
            }

            $nodeMasked = $masked
                || in_array($tag, ['input', 'select', 'textarea'], true)
                || array_key_exists('contenteditable', array_change_key_case($attributes))
                || array_key_exists('data-reel-mask', array_change_key_case($attributes));

            $this->validateAttributes($attributes, $nodeMasked);

            foreach (($node['childNodes'] ?? []) as $child) {
                $this->validateNode($child, $nodeMasked);
            }

            return;
        }

        if (($node['type'] ?? null) === 3) {
            if ($masked && ($node['textContent'] ?? null) !== '***') {
                $this->reject('unmasked_contenteditable_text');
            }

            if (($node['isStyle'] ?? false) === true) {
                $this->assertNoCssUrls($node['textContent'] ?? null);
            }
        }

        foreach (($node['childNodes'] ?? []) as $child) {
            $this->validateNode($child, $masked);
        }
    }

    private function validateAttributes(mixed $attributes, bool $masked): void
    {
        if (! is_array($attributes)) {
            $this->reject('invalid_node_attributes');
        }

        foreach ($attributes as $name => $value) {
            $lower = strtolower((string) $name);

            if (in_array($lower, self::HYDRATION_ATTRIBUTES, true)) {
                $this->reject('framework_hydration_payload');
            }

            if (in_array($lower, self::RESOURCE_ATTRIBUTES, true)) {
                $this->reject('resource_url');
            }

            if ($lower === 'value' && $masked && $value !== '***') {
                $this->reject('unmasked_form_value');
            }

            if (in_array($lower, ['style', '_csstext'], true)) {
                $this->assertNoCssUrls($value);
            }

            $this->assertNoDataUrl($value);
        }
    }

    private function scan(mixed $value, ?string $parentKey = null): void
    {
        if (is_string($value)) {
            $this->assertNoDataUrl($value);

            if (in_array($parentKey, ['style', '_csstext', 'csstext'], true)) {
                $this->assertNoCssUrls($value);
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $normalized = strtolower(str_replace(['-', ' '], '_', (string) $key));
            $compact = str_replace('_', '', $normalized);

            if (in_array($normalized, self::SENSITIVE_KEYS, true)
                || in_array($compact, self::SENSITIVE_KEYS, true)) {
                $this->reject('sensitive_browser_or_network_data');
            }

            if ($normalized === 'headers' && is_array($child)) {
                foreach (array_keys(array_change_key_case($child)) as $header) {
                    if (in_array($header, ['authorization', 'cookie', 'set-cookie'], true)) {
                        $this->reject('sensitive_browser_or_network_data');
                    }
                }
            }

            if (in_array($normalized, self::RESOURCE_ATTRIBUTES, true)) {
                $this->reject('resource_url');
            }

            $this->scan($child, $normalized);
        }
    }

    private function assertSanitizedMetadataUrl(mixed $value): void
    {
        if (! is_string($value) || str_contains($value, '?') || str_contains($value, '#')) {
            $this->reject('unsafe_page_url');
        }

        $this->assertNoDataUrl($value);
    }

    private function assertNoCssUrls(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $child) {
                $this->assertNoCssUrls($child);
            }

            return;
        }

        if (is_string($value) && preg_match('/(?:url\s*\(|@import\s+)/i', $value) === 1) {
            $this->reject('css_url');
        }
    }

    private function assertNoDataUrl(mixed $value): void
    {
        if (is_string($value) && preg_match('/^\s*data:/i', $value) === 1) {
            $this->reject('data_url_media');
        }
    }

    /** @param array<mixed> $data */
    private function isConsoleEvent(array $data): bool
    {
        return isset($data['plugin']) && is_string($data['plugin'])
            && str_contains(strtolower($data['plugin']), 'console');
    }

    /** @param array<mixed> $data */
    private function assertNoConsoleArguments(array $data): void
    {
        if (array_key_exists('args', $data)
            || array_key_exists('arguments', $data)
            || isset($data['payload']['args'])) {
            $this->reject('console_arguments');
        }
    }

    private function reject(string $reason): never
    {
        throw new IngestRejected($reason, 422);
    }
}
