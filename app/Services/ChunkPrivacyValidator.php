<?php

namespace App\Services;

use App\Exceptions\IngestRejected;
use Normalizer;

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
    private const array MASKED_TAGS = ['input', 'select', 'textarea'];

    public function validate(mixed $events): void
    {
        if (! is_array($events) || ! array_is_list($events) || $events === []) {
            $this->reject('invalid_event_batch');
        }

        foreach ($events as $event) {
            $this->validateEvent($event);
        }
    }

    private function validateEvent(mixed $event): void
    {
        if (! is_array($event) || ($event !== [] && array_is_list($event))) {
            $this->reject('invalid_event');
        }

        $this->assertOnlyKeys($event, ['type', 'timestamp', 'data']);
        $type = $event['type'] ?? null;
        $timestamp = $event['timestamp'] ?? null;
        $data = $event['data'] ?? null;

        if (! is_int($type)
            || ! is_int($timestamp)
            || $timestamp < 0
            || ! is_array($data)
            || ($data !== [] && array_is_list($data))) {
            $this->reject('invalid_event');
        }

        match ($type) {
            0, 1 => $this->assertOnlyKeys($data, []),
            2 => $this->validateFullSnapshot($data),
            3 => $this->validateIncrementalEvent($data),
            4 => $this->validateMetadataEvent($data),
            5 => $this->validateCustomEvent($data),
            default => $this->reject('unknown_event_type'),
        };
    }

    /** @param array<mixed> $data */
    private function validateFullSnapshot(array $data): void
    {
        $this->assertOnlyKeys($data, ['node', 'initialOffset']);

        if (! array_key_exists('node', $data)) {
            $this->reject('invalid_full_snapshot');
        }

        $this->validateNode($data['node'], false);

        if (array_key_exists('initialOffset', $data)) {
            $offset = $data['initialOffset'];

            if (! is_array($offset) || array_is_list($offset)) {
                $this->reject('invalid_full_snapshot');
            }

            $this->assertOnlyKeys($offset, ['top', 'left']);
            $this->assertNumericFields($offset, ['top', 'left']);
        }
    }

    /** @param array<mixed> $data */
    private function validateMetadataEvent(array $data): void
    {
        $this->assertOnlyKeys($data, ['href', 'width', 'height']);

        if (array_key_exists('href', $data)) {
            $href = $this->canonicalString($data['href']);

            if (str_contains($href, '?') || str_contains($href, '#') || preg_match('/^\s*data:/i', $href) === 1) {
                $this->reject('unsafe_page_url');
            }
        }

        $this->assertNumericFields($data, array_values(array_intersect(['width', 'height'], array_keys($data))));
    }

    /** @param array<mixed> $data */
    private function validateCustomEvent(array $data): void
    {
        $this->assertOnlyKeys($data, ['tag', 'payload']);

        if (($data['tag'] ?? null) !== 'reel.error' || ! isset($data['payload']) || ! is_array($data['payload'])) {
            $this->reject('unknown_custom_event');
        }

        $payload = $data['payload'];
        $this->assertOnlyKeys($payload, ['method', 'path', 'status']);

        if (! isset($payload['method'], $payload['path'], $payload['status'])
            || ! is_string($payload['method'])
            || ! is_string($payload['path'])
            || ! is_int($payload['status'])
            || str_contains($payload['path'], '?')
            || str_contains($payload['path'], '#')) {
            $this->reject('invalid_custom_event');
        }
    }

    /** @param array<mixed> $data */
    private function validateIncrementalEvent(array $data): void
    {
        $source = $data['source'] ?? null;

        if (! is_int($source)) {
            $this->reject('invalid_incremental_source');
        }

        match ($source) {
            0 => $this->validateMutation($data),
            1, 6, 12 => $this->validatePositionMutation($data),
            2 => $this->validateMouseInteraction($data),
            3 => $this->validateScroll($data),
            4 => $this->validateViewportResize($data),
            5 => $this->validateInput($data),
            8 => $this->validateStylesheetRule($data),
            13 => $this->validateStyleDeclaration($data),
            14 => $this->validateSelection($data),
            15 => $this->validateAdoptedStylesheet($data),
            default => $this->reject('unsupported_incremental_source'),
        };
    }

    /** @param array<mixed> $data */
    private function validateMutation(array $data): void
    {
        $this->assertOnlyKeys($data, ['source', 'adds', 'removes', 'texts', 'attributes']);

        foreach ($this->optionalList($data, 'adds') as $addition) {
            $this->assertObject($addition, 'invalid_node_addition');
            $this->assertOnlyKeys($addition, ['parentId', 'nextId', 'node']);
            $this->assertIntegerFields($addition, ['parentId']);

            if (array_key_exists('nextId', $addition) && ! is_int($addition['nextId']) && $addition['nextId'] !== null) {
                $this->reject('invalid_node_addition');
            }

            $this->validateNode($addition['node'] ?? null, false);
        }

        foreach ($this->optionalList($data, 'removes') as $removal) {
            $this->assertObject($removal, 'invalid_node_removal');
            $this->assertOnlyKeys($removal, ['parentId', 'id']);
            $this->assertIntegerFields($removal, ['parentId', 'id']);
        }

        foreach ($this->optionalList($data, 'texts') as $text) {
            $this->assertObject($text, 'invalid_text_mutation');
            $this->assertOnlyKeys($text, ['id', 'value']);
            $this->assertIntegerFields($text, ['id']);

            if (! is_string($text['value'] ?? null)) {
                $this->reject('invalid_text_mutation');
            }

            $this->assertNoDataUrl($text['value']);
        }

        foreach ($this->optionalList($data, 'attributes') as $mutation) {
            $this->assertObject($mutation, 'invalid_attribute_mutation');
            $this->assertOnlyKeys($mutation, ['id', 'attributes']);
            $this->assertIntegerFields($mutation, ['id']);
            $this->validateAttributes($mutation['attributes'] ?? null, true);
        }
    }

    /** @param array<mixed> $data */
    private function validatePositionMutation(array $data): void
    {
        $this->assertOnlyKeys($data, ['source', 'positions']);

        foreach ($this->requiredList($data, 'positions') as $position) {
            $this->assertObject($position, 'invalid_position_mutation');
            $this->assertOnlyKeys($position, ['x', 'y', 'id', 'timeOffset']);
            $this->assertNumericFields($position, ['x', 'y', 'timeOffset']);
            $this->assertIntegerFields($position, ['id']);
        }
    }

    /** @param array<mixed> $data */
    private function validateMouseInteraction(array $data): void
    {
        $this->assertOnlyKeys($data, ['source', 'type', 'id', 'x', 'y']);
        $this->assertIntegerFields($data, ['type', 'id']);
        $this->assertNumericFields($data, array_values(array_intersect(['x', 'y'], array_keys($data))));
    }

    /** @param array<mixed> $data */
    private function validateScroll(array $data): void
    {
        $this->assertOnlyKeys($data, ['source', 'id', 'x', 'y']);
        $this->assertIntegerFields($data, ['id']);
        $this->assertNumericFields($data, ['x', 'y']);
    }

    /** @param array<mixed> $data */
    private function validateViewportResize(array $data): void
    {
        $this->assertOnlyKeys($data, ['source', 'width', 'height']);
        $this->assertNumericFields($data, ['width', 'height']);
    }

    /** @param array<mixed> $data */
    private function validateInput(array $data): void
    {
        $this->assertOnlyKeys($data, ['source', 'id', 'text', 'isChecked', 'userTriggered']);
        $this->assertIntegerFields($data, ['id']);

        if (($data['text'] ?? null) !== '***'
            || (array_key_exists('isChecked', $data) && ! is_bool($data['isChecked']))
            || (array_key_exists('userTriggered', $data) && ! is_bool($data['userTriggered']))) {
            $this->reject('unmasked_form_value');
        }
    }

    /** @param array<mixed> $data */
    private function validateStylesheetRule(array $data): void
    {
        $this->assertOnlyKeys($data, ['source', 'id', 'styleId', 'adds', 'removes']);
        $this->assertOptionalIntegerFields($data, ['id', 'styleId']);

        foreach ($this->optionalList($data, 'adds') as $addition) {
            $this->assertObject($addition, 'invalid_stylesheet_rule');
            $this->assertOnlyKeys($addition, ['rule', 'index']);
            $this->assertSafeCss($addition['rule'] ?? null);
            $this->assertIndex($addition['index'] ?? null);
        }

        foreach ($this->optionalList($data, 'removes') as $removal) {
            $this->assertObject($removal, 'invalid_stylesheet_rule');
            $this->assertOnlyKeys($removal, ['index']);
            $this->assertIndex($removal['index'] ?? null);
        }
    }

    /** @param array<mixed> $data */
    private function validateStyleDeclaration(array $data): void
    {
        $this->assertOnlyKeys($data, ['source', 'id', 'index', 'set', 'remove']);
        $this->assertIntegerFields($data, ['id']);
        $this->assertIndex($data['index'] ?? null);

        if (isset($data['set'])) {
            $this->assertObject($data['set'], 'invalid_style_declaration');
            $this->assertOnlyKeys($data['set'], ['property', 'value', 'priority']);
            $this->canonicalString($data['set']['property'] ?? null);
            $this->assertSafeCss($data['set']['value'] ?? null);

            if (isset($data['set']['priority']) && ! is_string($data['set']['priority'])) {
                $this->reject('invalid_style_declaration');
            }
        }

        if (isset($data['remove'])) {
            $this->assertObject($data['remove'], 'invalid_style_declaration');
            $this->assertOnlyKeys($data['remove'], ['property']);
            $this->canonicalString($data['remove']['property'] ?? null);
        }

        if (! isset($data['set']) && ! isset($data['remove'])) {
            $this->reject('invalid_style_declaration');
        }
    }

    /** @param array<mixed> $data */
    private function validateSelection(array $data): void
    {
        $this->assertOnlyKeys($data, ['source', 'ranges']);

        foreach ($this->requiredList($data, 'ranges') as $range) {
            $this->assertObject($range, 'invalid_selection');
            $this->assertOnlyKeys($range, ['start', 'startOffset', 'end', 'endOffset']);
            $this->assertIntegerFields($range, ['start', 'startOffset', 'end', 'endOffset']);
        }
    }

    /** @param array<mixed> $data */
    private function validateAdoptedStylesheet(array $data): void
    {
        $this->assertOnlyKeys($data, ['source', 'id', 'styleIds', 'styles']);
        $this->assertIntegerFields($data, ['id']);

        foreach ($this->requiredList($data, 'styleIds') as $styleId) {
            if (! is_int($styleId)) {
                $this->reject('invalid_adopted_stylesheet');
            }
        }

        foreach ($this->requiredList($data, 'styles') as $style) {
            $this->assertObject($style, 'invalid_adopted_stylesheet');
            $this->assertOnlyKeys($style, ['styleId', 'rules']);
            $this->assertIntegerFields($style, ['styleId']);

            foreach ($this->requiredList($style, 'rules') as $rule) {
                $this->assertObject($rule, 'invalid_adopted_stylesheet');
                $this->assertOnlyKeys($rule, ['rule', 'index']);
                $this->assertSafeCss($rule['rule'] ?? null);
                $this->assertIndex($rule['index'] ?? null);
            }
        }
    }

    private function validateNode(mixed $node, bool $masked): void
    {
        $this->assertObject($node, 'invalid_node');
        $type = $node['type'] ?? null;

        if (! is_int($type)) {
            $this->reject('invalid_node_type');
        }

        match ($type) {
            0 => $this->validateDocumentNode($node, $masked),
            1 => $this->validateDocumentTypeNode($node),
            2 => $this->validateElementNode($node, $masked),
            3, 4, 5 => $this->validateTextNode($node, $masked),
            default => $this->reject('unknown_node_type'),
        };
    }

    /** @param array<mixed> $node */
    private function validateDocumentNode(array $node, bool $masked): void
    {
        $this->assertOnlyKeys($node, ['type', 'id', 'childNodes', 'compatMode', 'rootId']);
        $this->assertIntegerFields($node, ['id']);
        $this->assertOptionalIntegerFields($node, ['rootId']);

        if (isset($node['compatMode']) && ! is_string($node['compatMode'])) {
            $this->reject('invalid_document_node');
        }

        $this->validateChildren($node, $masked);
    }

    /** @param array<mixed> $node */
    private function validateDocumentTypeNode(array $node): void
    {
        $this->assertOnlyKeys($node, ['type', 'id', 'name', 'publicId', 'systemId', 'rootId']);
        $this->assertIntegerFields($node, ['id']);
        $this->assertOptionalIntegerFields($node, ['rootId']);

        foreach (['name', 'publicId', 'systemId'] as $field) {
            if (isset($node[$field]) && ! is_string($node[$field])) {
                $this->reject('invalid_document_type_node');
            }
        }
    }

    /** @param array<mixed> $node */
    private function validateElementNode(array $node, bool $masked): void
    {
        $this->assertOnlyKeys($node, [
            'type', 'id', 'tagName', 'attributes', 'childNodes', 'isSVG', 'needBlock', 'rootId',
        ]);
        $this->assertIntegerFields($node, ['id']);
        $this->assertOptionalIntegerFields($node, ['rootId']);

        if ((isset($node['isSVG']) && ! is_bool($node['isSVG']))
            || (isset($node['needBlock']) && ! is_bool($node['needBlock']))) {
            $this->reject('invalid_element_node');
        }

        $tag = $this->canonicalName($node['tagName'] ?? null);

        if (in_array($tag, self::BLOCKED_TAGS, true)) {
            $this->reject('blocked_media_element');
        }

        $attributes = $node['attributes'] ?? null;
        $attributeNames = $this->validateAttributes($attributes, $masked || in_array($tag, self::MASKED_TAGS, true));
        $nodeMasked = $masked
            || in_array($tag, self::MASKED_TAGS, true)
            || in_array('contenteditable', $attributeNames, true)
            || in_array('data-reel-mask', $attributeNames, true);

        if ($nodeMasked && array_key_exists('value', $attributes) && $attributes['value'] !== '***') {
            $this->reject('unmasked_form_value');
        }

        $this->validateChildren($node, $nodeMasked);
    }

    /** @param array<mixed> $node */
    private function validateTextNode(array $node, bool $masked): void
    {
        $this->assertOnlyKeys($node, ['type', 'id', 'textContent', 'isStyle', 'rootId']);
        $this->assertIntegerFields($node, ['id']);
        $this->assertOptionalIntegerFields($node, ['rootId']);

        if (! is_string($node['textContent'] ?? null)
            || (isset($node['isStyle']) && ! is_bool($node['isStyle']))) {
            $this->reject('invalid_text_node');
        }

        if ($masked && $node['textContent'] !== '***') {
            $this->reject('unmasked_contenteditable_text');
        }

        if (($node['isStyle'] ?? false) === true) {
            $this->assertSafeCss($node['textContent']);
        } else {
            $this->assertNoDataUrl($node['textContent']);
        }
    }

    /**
     * @return list<string>
     */
    private function validateAttributes(mixed $attributes, bool $requireMaskedValue): array
    {
        $this->assertObject($attributes, 'invalid_node_attributes');
        $names = [];

        foreach ($attributes as $name => $value) {
            if (! is_string($name) || (! is_string($value) && ! is_bool($value))) {
                $this->reject('invalid_node_attributes');
            }

            $canonical = $this->canonicalName($name);

            if (in_array($canonical, $names, true)
                || in_array($canonical, self::HYDRATION_ATTRIBUTES, true)
                || in_array($canonical, self::RESOURCE_ATTRIBUTES, true)
                || str_starts_with($canonical, 'on')) {
                $this->reject('unsafe_attribute');
            }

            if ($canonical === 'value' && $requireMaskedValue && $value !== '***') {
                $this->reject('unmasked_form_value');
            }

            if (in_array($canonical, ['style', '_csstext'], true)) {
                $this->assertSafeCss($value);
            } elseif (is_string($value)) {
                $this->assertNoDataUrl($value);
            }

            $names[] = $canonical;
        }

        return $names;
    }

    /** @param array<mixed> $node */
    private function validateChildren(array $node, bool $masked): void
    {
        foreach ($this->requiredList($node, 'childNodes') as $child) {
            $this->validateNode($child, $masked);
        }
    }

    private function assertSafeCss(mixed $value): void
    {
        $css = $this->canonicalString($value);
        $decoded = preg_replace_callback(
            '/\\\\([0-9a-fA-F]{1,6})(?:\r\n|[ \n\r\t\f])?|\\\\([^\r\n\f])/u',
            function (array $match): string {
                if (($match[1] ?? '') !== '') {
                    $codepoint = hexdec($match[1]);

                    if ($codepoint === 0 || $codepoint > 0x10FFFF || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
                        $this->reject('invalid_css_escape');
                    }

                    return mb_chr($codepoint, 'UTF-8');
                }

                return $match[2];
            },
            $css,
        );

        if (! is_string($decoded)
            || str_contains($decoded, '\\')
            || preg_match('/(?:url\s*\(|@import\b)/iu', $decoded) === 1) {
            $this->reject('css_url');
        }

        $this->assertNoDataUrl($decoded);
    }

    private function assertNoDataUrl(mixed $value): void
    {
        $canonical = $this->canonicalString($value);

        if (preg_match('/^\s*data:/i', $canonical) === 1) {
            $this->reject('data_url_media');
        }
    }

    private function canonicalName(mixed $value): string
    {
        $canonical = mb_strtolower(trim($this->canonicalString($value)), 'UTF-8');

        if ($canonical === '' || preg_match('/^[a-z_:][a-z0-9_.:-]*$/u', $canonical) !== 1) {
            $this->reject('invalid_name');
        }

        return $canonical;
    }

    private function canonicalString(mixed $value): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            $this->reject('invalid_string');
        }

        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = Normalizer::normalize($decoded, Normalizer::FORM_C);

        if (! is_string($normalized)) {
            $this->reject('invalid_string');
        }

        return $normalized;
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $allowed
     */
    private function assertOnlyKeys(array $value, array $allowed): void
    {
        foreach (array_keys($value) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                $this->reject('unknown_field');
            }
        }
    }

    private function assertObject(mixed $value, string $reason): void
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->reject($reason);
        }
    }

    /**
     * @param  array<mixed>  $value
     * @return list<mixed>
     */
    private function requiredList(array $value, string $key): array
    {
        if (! isset($value[$key]) || ! is_array($value[$key]) || ! array_is_list($value[$key])) {
            $this->reject('invalid_list');
        }

        return $value[$key];
    }

    /**
     * @param  array<mixed>  $value
     * @return list<mixed>
     */
    private function optionalList(array $value, string $key): array
    {
        if (! array_key_exists($key, $value)) {
            return [];
        }

        return $this->requiredList($value, $key);
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $fields
     */
    private function assertIntegerFields(array $value, array $fields): void
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value) || ! is_int($value[$field])) {
                $this->reject('invalid_integer_field');
            }
        }
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $fields
     */
    private function assertOptionalIntegerFields(array $value, array $fields): void
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $value) && ! is_int($value[$field])) {
                $this->reject('invalid_integer_field');
            }
        }
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $fields
     */
    private function assertNumericFields(array $value, array $fields): void
    {
        foreach ($fields as $field) {
            if (! array_key_exists($field, $value) || (! is_int($value[$field]) && ! is_float($value[$field]))) {
                $this->reject('invalid_numeric_field');
            }
        }
    }

    private function assertIndex(mixed $index): void
    {
        if (is_int($index)) {
            return;
        }

        if (! is_array($index) || ! array_is_list($index)) {
            $this->reject('invalid_stylesheet_index');
        }

        foreach ($index as $part) {
            if (! is_int($part)) {
                $this->reject('invalid_stylesheet_index');
            }
        }
    }

    private function reject(string $reason): never
    {
        throw new IngestRejected($reason, 422);
    }
}
