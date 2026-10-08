<?php

namespace App\Services;

use App\Enums\CaptureSeverity;
use App\Exceptions\IngestRejected;
use App\Models\Application;
use Illuminate\Support\Str;

/**
 * Enforces an application's saved capture policy on the server, at ingest, where the
 * monitored browser cannot influence the outcome. Protection is monotonic: every branch
 * here removes or replaces captured data and none of it can restore masked data.
 */
class ApplicationCapturePolicy
{
    public const string MASK = '***';

    /**
     * The bounded selector grammar this policy can match against a serialized node:
     * an optional tag name followed by any number of `#id` / `.class` tokens.
     */
    public const string SELECTOR_PATTERN = '/^([a-zA-Z][a-zA-Z0-9-]*)?((?:[#.][A-Za-z_][A-Za-z0-9_-]*)*)$/';

    private bool $allText = false;

    /** @var list<array{tag: ?string, id: ?string, classes: list<string>}> */
    private array $maskSelectors = [];

    /** @var list<array{tag: ?string, id: ?string, classes: list<string>}> */
    private array $blockSelectors = [];

    /** @var array<int, true> */
    private array $maskedNodeIds = [];

    /** @var array<int, true> */
    private array $styleNodeIds = [];

    /**
     * Deterministic sampling bucket for a session id, so a session is wholly inside or
     * wholly outside the configured share for every chunk it ever uploads.
     */
    public static function sessionBucket(string $sessionId): int
    {
        return (int) (hexdec(substr(hash('sha256', $sessionId), 0, 8)) % 100);
    }

    public function assertSessionSampled(Application $application, string $sessionId): void
    {
        if (self::sessionBucket($sessionId) >= $application->sampling_percent) {
            throw new IngestRejected('session_not_sampled', 403);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function assertPathsAllowed(Application $application, array $events, ?string $currentPath): void
    {
        $patterns = $application->excluded_paths;

        if ($patterns === []) {
            return;
        }

        $paths = [];

        foreach ($events as $event) {
            $data = $event['data'] ?? null;

            if (($event['type'] ?? null) !== 4 || ! is_array($data) || ! is_string($data['href'] ?? null)) {
                continue;
            }

            if ($data['href'] !== '') {
                $paths[] = $data['href'];
            }
        }

        if ($paths === [] && $currentPath !== null && $currentPath !== '') {
            $paths[] = $currentPath;
        }

        foreach ($paths as $path) {
            if (Str::is($patterns, $path)) {
                throw new IngestRejected('excluded_path', 403);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    public function apply(Application $application, array $events): array
    {
        $this->allText = $application->severity === CaptureSeverity::AllText;
        $this->maskSelectors = $this->selectors($application->mask_selectors);
        $this->blockSelectors = $this->selectors($application->block_selectors);
        $this->maskedNodeIds = [];
        $this->styleNodeIds = [];

        if (! $this->allText && $this->maskSelectors === [] && $this->blockSelectors === []) {
            return $events;
        }

        $enforced = [];

        foreach ($events as $event) {
            $enforced[] = $this->applyToEvent($event);
        }

        return $enforced;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function applyToEvent(array $event): array
    {
        $data = $event['data'] ?? null;

        if (! is_array($data)) {
            return $event;
        }

        if ($event['type'] === 2 && array_key_exists('node', $data)) {
            $data['node'] = $this->applyToNode($data['node'], false, false);
            $event['data'] = $data;

            return $event;
        }

        if ($event['type'] !== 3 || ($data['source'] ?? null) !== 0) {
            return $event;
        }

        if (isset($data['adds']) && is_array($data['adds'])) {
            $adds = [];

            foreach ($data['adds'] as $addition) {
                if (! is_array($addition)) {
                    $adds[] = $addition;

                    continue;
                }

                $parentId = $addition['parentId'] ?? null;
                $addition['node'] = $this->applyToNode(
                    $addition['node'] ?? null,
                    is_int($parentId) && isset($this->maskedNodeIds[$parentId]),
                    is_int($parentId) && isset($this->styleNodeIds[$parentId]),
                );
                $adds[] = $addition;
            }

            $data['adds'] = $adds;
        }

        if (isset($data['texts']) && is_array($data['texts'])) {
            $texts = [];

            foreach ($data['texts'] as $text) {
                if (is_array($text) && is_int($text['id'] ?? null) && $this->textIsMasked($text['id'])) {
                    $text['value'] = self::MASK;
                }

                $texts[] = $text;
            }

            $data['texts'] = $texts;
        }

        $event['data'] = $data;

        return $event;
    }

    private function textIsMasked(int $nodeId): bool
    {
        if (isset($this->styleNodeIds[$nodeId])) {
            return false;
        }

        return $this->allText || isset($this->maskedNodeIds[$nodeId]);
    }

    private function applyToNode(mixed $node, bool $masked, bool $parentIsStyle): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        $id = $node['id'] ?? null;

        if ($node['type'] === 2) {
            $attributes = is_array($node['attributes'] ?? null) ? $node['attributes'] : [];
            $tag = is_string($node['tagName'] ?? null) ? mb_strtolower($node['tagName'], 'UTF-8') : '';

            if ($this->matches($this->blockSelectors, $tag, $attributes)) {
                return $this->placeholder($node, $tag, $attributes);
            }

            $masked = $masked || $this->matches($this->maskSelectors, $tag, $attributes);

            if (is_int($id)) {
                if ($masked) {
                    $this->maskedNodeIds[$id] = true;
                }

                if ($tag === 'style') {
                    $this->styleNodeIds[$id] = true;
                }
            }

            return $this->applyToChildren($node, $masked, $tag === 'style');
        }

        if (in_array($node['type'], [3, 4, 5], true)) {
            if ($parentIsStyle) {
                if (is_int($id)) {
                    $this->styleNodeIds[$id] = true;
                }

                return $node;
            }

            if (is_int($id) && $masked) {
                $this->maskedNodeIds[$id] = true;
            }

            if ($masked || $this->allText) {
                $node['textContent'] = self::MASK;
            }

            return $node;
        }

        return $this->applyToChildren($node, $masked, false);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function applyToChildren(array $node, bool $masked, bool $isStyle): array
    {
        if (! is_array($node['childNodes'] ?? null)) {
            return $node;
        }

        $children = [];

        foreach ($node['childNodes'] as $child) {
            $children[] = $this->applyToNode($child, $masked, $isStyle);
        }

        $node['childNodes'] = $children;

        return $node;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<mixed>  $attributes
     * @return array<string, mixed>
     */
    private function placeholder(array $node, string $tag, array $attributes): array
    {
        $kept = [];

        foreach (['class', 'width', 'height', 'style'] as $name) {
            if (array_key_exists($name, $attributes)) {
                $kept[$name] = $attributes[$name];
            }
        }

        $kept['data-reel-blocked'] = $tag === '' ? 'element' : $tag;
        $node['tagName'] = 'div';
        $node['attributes'] = $kept;
        $node['childNodes'] = [];

        return $node;
    }

    /**
     * @param  list<array{tag: ?string, id: ?string, classes: list<string>}>  $selectors
     * @param  array<mixed>  $attributes
     */
    private function matches(array $selectors, string $tag, array $attributes): bool
    {
        if ($selectors === []) {
            return false;
        }

        $id = is_string($attributes['id'] ?? null) ? $attributes['id'] : null;
        $classAttribute = is_string($attributes['class'] ?? null) ? trim($attributes['class']) : '';
        $classes = $classAttribute === '' ? [] : (preg_split('/\s+/', $classAttribute) ?: []);

        foreach ($selectors as $selector) {
            if ($selector['tag'] !== null && $selector['tag'] !== $tag) {
                continue;
            }

            if ($selector['id'] !== null && $selector['id'] !== $id) {
                continue;
            }

            foreach ($selector['classes'] as $class) {
                if (! in_array($class, $classes, true)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $selectors
     * @return list<array{tag: ?string, id: ?string, classes: list<string>}>
     */
    private function selectors(array $selectors): array
    {
        $parsed = [];

        foreach ($selectors as $selector) {
            $candidate = self::parseSelector($selector);

            if ($candidate !== null) {
                $parsed[] = $candidate;
            }
        }

        return $parsed;
    }

    /**
     * @return array{tag: ?string, id: ?string, classes: list<string>}|null
     */
    public static function parseSelector(string $selector): ?array
    {
        $selector = trim($selector);

        if (preg_match(self::SELECTOR_PATTERN, $selector, $matches) !== 1) {
            return null;
        }

        $tag = $matches[1] === '' ? null : mb_strtolower($matches[1], 'UTF-8');
        $id = null;
        $classes = [];

        preg_match_all('/([#.])([A-Za-z_][A-Za-z0-9_-]*)/', $matches[2], $tokens, PREG_SET_ORDER);

        foreach ($tokens as $token) {
            if ($token[1] === '#') {
                if ($id !== null) {
                    return null;
                }

                $id = $token[2];

                continue;
            }

            $classes[] = $token[2];
        }

        if ($tag === null && $id === null && $classes === []) {
            return null;
        }

        return ['tag' => $tag, 'id' => $id, 'classes' => $classes];
    }
}
