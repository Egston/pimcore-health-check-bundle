<?php

declare(strict_types=1);

namespace Egston\PimcoreHealthCheckBundle\Tests\Unit\DependencyInjection;

use Egston\PimcoreHealthCheckBundle\DependencyInjection\EgstonPimcoreHealthCheckExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Two endpoints whose `name` differs only in non-alphanumeric characters
 * normalize to the same service ID after slug normalization. Without
 * detection, the second definition silently overwrites the first; the
 * resulting probe set contains only one of the two checks the operator
 * configured. Both `graphql:` and `graphql_internal:` blocks are covered.
 */
final class CollisionDetectionTest extends TestCase
{
    public function testGraphqlInternalCollisionThrows(): void
    {
        $extension = new EgstonPimcoreHealthCheckExtension();
        $container = new ContainerBuilder();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/normalize to the same service ID/');

        // normalizeServiceIdSegment preserves `-`, `_`, `.`; replaces other
        // non-alphanumerics with `_`. So "my!api" and "my_api" both
        // normalize to "my_api" — that's the collision.
        $extension->load([['graphql_internal' => [
            'enabled' => true,
            'endpoints' => [
                ['name' => 'my!api', 'path' => '/safe-a'],
                ['name' => 'my_api', 'path' => '/safe-b'],
            ],
        ]]], $container);
    }

    public function testGraphqlHttpCollisionThrows(): void
    {
        $extension = new EgstonPimcoreHealthCheckExtension();
        $container = new ContainerBuilder();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/normalize to the same service ID/');

        $extension->load([['graphql' => [
            'enabled' => true,
            'endpoints' => [
                ['name' => 'partner api', 'url' => 'https://example.test/a'],
                ['name' => 'partner_api', 'url' => 'https://example.test/b'],
            ],
        ]]], $container);
    }

    public function testDistinctNamesProduceDistinctServices(): void
    {
        $extension = new EgstonPimcoreHealthCheckExtension();
        $container = new ContainerBuilder();

        $extension->load([['graphql_internal' => [
            'enabled' => true,
            'endpoints' => [
                ['name' => 'first', 'path' => '/safe-a'],
                ['name' => 'second', 'path' => '/safe-b'],
            ],
        ]]], $container);

        $this->assertTrue($container->hasDefinition('egston_pimcore_health_check.graphql_internal.first'));
        $this->assertTrue($container->hasDefinition('egston_pimcore_health_check.graphql_internal.second'));
    }

    public function testCollisionAcrossGraphqlAndInternalIsNotACollision(): void
    {
        // Service-ID prefixes differ (graphql.X vs graphql_internal.X), so
        // identical names across the two blocks must not collide.
        $extension = new EgstonPimcoreHealthCheckExtension();
        $container = new ContainerBuilder();

        $extension->load([[
            'graphql' => [
                'enabled' => true,
                'endpoints' => [['name' => 'shared', 'url' => 'https://example.test/a']],
            ],
            'graphql_internal' => [
                'enabled' => true,
                'endpoints' => [['name' => 'shared', 'path' => '/safe']],
            ],
        ]], $container);

        $this->assertTrue($container->hasDefinition('egston_pimcore_health_check.graphql.shared'));
        $this->assertTrue($container->hasDefinition('egston_pimcore_health_check.graphql_internal.shared'));
    }
}
