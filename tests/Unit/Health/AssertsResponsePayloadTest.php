<?php

declare(strict_types=1);

namespace Egston\PimcoreHealthCheckBundle\Tests\Unit\Health;

use Egston\PimcoreHealthCheckBundle\Health\AssertsResponsePayload;
use PHPUnit\Framework\TestCase;

/**
 * Trait helpers are private by design (the trait composes into checks that
 * shouldn't expose them as public API). Tests drive the four helpers that
 * form the trait's effective contract — `resolvePath`, `assertEquals`,
 * `assertContains`, `assertEmptyState` — by aliasing them to public via
 * `use AssertsResponsePayload { method as public; }` in an anonymous
 * fixture; production visibility is unchanged. The remaining trait
 * privates (`normalizeSegment`, `stringify`) are pure implementation
 * details exercised transitively through their callers above.
 */
final class AssertsResponsePayloadTest extends TestCase
{
    private function fixture(): object
    {
        return new class () {
            use AssertsResponsePayload {
                resolvePath as public;
                assertEquals as public;
                assertContains as public;
                assertEmptyState as public;
            }
        };
    }

    public function testResolvePathTraversesDotNotation(): void
    {
        $payload = ['data' => ['health' => ['status' => 'ok']]];

        self::assertSame('ok', $this->fixture()->resolvePath($payload, 'data.health.status'));
    }

    public function testResolvePathReturnsEntirePayloadWhenPathIsNullOrEmpty(): void
    {
        $payload = ['data' => 1];

        self::assertSame($payload, $this->fixture()->resolvePath($payload, null));
        self::assertSame($payload, $this->fixture()->resolvePath($payload, ''));
        self::assertSame($payload, $this->fixture()->resolvePath($payload, '   '));
    }

    public function testResolvePathSupportsNumericArrayIndices(): void
    {
        $payload = ['edges' => [['node' => 'first'], ['node' => 'second']]];

        self::assertSame('first', $this->fixture()->resolvePath($payload, 'edges.0.node'));
        self::assertSame('second', $this->fixture()->resolvePath($payload, 'edges.1.node'));
    }

    public function testResolvePathThrowsOnMissingSegment(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/missing segment "absent"/');

        $this->fixture()->resolvePath(['present' => 1], 'absent');
    }

    public function testResolvePathThrowsWhenTraversingNonArray(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unable to traverse path/');

        $this->fixture()->resolvePath(['leaf' => 'string-value'], 'leaf.deeper');
    }

    public function testAssertEqualsIsNoopWhenExpectedIsNull(): void
    {
        $this->fixture()->assertEquals('anything', null);
        // Reaching here means no exception thrown.
        self::assertTrue(true);
    }

    public function testAssertEqualsThrowsOnMismatch(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Expected value .*Query.* but got .*Mutation/');

        $this->fixture()->assertEquals('Mutation', 'Query');
    }

    public function testAssertContainsThrowsWhenValueNotIterable(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not iterable/');

        $this->fixture()->assertContains('not-an-array', 'expected');
    }

    public function testAssertContainsAcceptsArraysAndIterables(): void
    {
        $this->fixture()->assertContains(['a', 'b', 'c'], 'b');
        self::assertTrue(true);
    }

    public function testAssertContainsThrowsWhenElementMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found in iterable/');

        $this->fixture()->assertContains(['a', 'b'], 'c');
    }

    public function testAssertEmptyStateAcceptsNullExpectation(): void
    {
        $this->fixture()->assertEmptyState([], null);
        $this->fixture()->assertEmptyState(['anything'], null);
        self::assertTrue(true);
    }

    public function testAssertEmptyStateThrowsOnExpectedNonEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/non-empty/');

        $this->fixture()->assertEmptyState([], false);
    }

    public function testAssertEmptyStateThrowsOnExpectedEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/be empty/');

        $this->fixture()->assertEmptyState(['something'], true);
    }
}
