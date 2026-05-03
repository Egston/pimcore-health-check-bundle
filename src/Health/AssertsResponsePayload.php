<?php

namespace Egston\PimcoreHealthCheckBundle\Health;

/**
 * Shared response-payload traversal and assertion helpers for GraphQL-style
 * health checks. Used by checks that obtain a JSON-decoded payload (whether
 * via HTTP, a Symfony sub-request, or another transport) and need to validate
 * the structure of the result.
 *
 * All helpers throw `\RuntimeException` on assertion failure with a message
 * that fully describes the mismatch — callers don't need to add their own
 * structured logging on top.
 */
trait AssertsResponsePayload
{
    /**
     * Walk a dot-separated path through a decoded payload and return the
     * value at that location.
     *
     * @param array<mixed> $payload
     */
    private function resolvePath(array $payload, ?string $path): mixed
    {
        if ($path === null || trim($path) === '') {
            return $payload;
        }

        $segments = explode('.', $path);
        $current = $payload;

        foreach ($segments as $segment) {
            $key = $this->normalizeSegment($segment);

            if (!is_array($current)) {
                throw new \RuntimeException(sprintf(
                    'Unable to traverse path "%s" at segment "%s"; current value is %s, not an array.',
                    $path,
                    $segment,
                    get_debug_type($current)
                ));
            }

            if (!array_key_exists($key, $current)) {
                throw new \RuntimeException(sprintf(
                    'Response path "%s" not found in payload (missing segment "%s"; available keys: %s).',
                    $path,
                    $segment,
                    implode(', ', array_map(static fn ($k) => (string) $k, array_keys($current)))
                ));
            }

            $current = $current[$key];
        }

        return $current;
    }

    private function normalizeSegment(string $segment): string|int
    {
        $trimmed = trim($segment);

        if (ctype_digit($trimmed)) {
            return (int) $trimmed;
        }

        return $trimmed;
    }

    private function assertEquals(mixed $value, mixed $expected): void
    {
        if ($expected === null) {
            return;
        }

        if ($value !== $expected) {
            throw new \RuntimeException(sprintf(
                'Expected value "%s" but got "%s".',
                $this->stringify($expected),
                $this->stringify($value)
            ));
        }
    }

    private function assertContains(mixed $value, mixed $expectedElement): void
    {
        if ($expectedElement === null) {
            return;
        }

        if (!is_iterable($value)) {
            throw new \RuntimeException('Value is not iterable; cannot check for containment.');
        }

        $items = is_array($value) ? $value : iterator_to_array($value);

        if (!in_array($expectedElement, $items, true)) {
            throw new \RuntimeException(sprintf(
                'Expected element "%s" not found in iterable.',
                $this->stringify($expectedElement)
            ));
        }
    }

    private function assertEmptyState(mixed $value, ?bool $expectedEmpty): void
    {
        if ($expectedEmpty === null) {
            return;
        }

        $isEmpty = empty($value);

        if ($expectedEmpty && !$isEmpty) {
            throw new \RuntimeException('Expected value to be empty.');
        }

        if (!$expectedEmpty && $isEmpty) {
            throw new \RuntimeException('Expected value to be non-empty.');
        }
    }

    private function stringify(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return var_export($value, true);
        }

        $encoded = json_encode($value);

        if ($encoded === false) {
            return '[unserializable value]';
        }

        return $encoded;
    }
}
