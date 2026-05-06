<?php

declare(strict_types=1);

namespace Egston\PimcoreHealthCheckBundle\Tests\Unit\DependencyInjection;

use Egston\PimcoreHealthCheckBundle\DependencyInjection\EgstonPimcoreHealthCheckExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Boundary tests for the SUB_REQUEST path-safety guard. Drives the public
 * `load()` API rather than poking the private helper, so the test pins the
 * observable contract: which `path:` values cause container compilation to
 * fail with a clear message.
 */
final class PathSafetyTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedPaths(): array
    {
        return [
            'plain healthz' => ['/healthz'],
            'datahub public-content' => ['/pimcore-graphql-webservices/public-content'],
            'administrator (boundary anchor: not /admin/)' => ['/administrator'],
            'admin-suffixed segment' => ['/foo/adminstuff'],
            '..bar (not a `..` segment)' => ['/foo/..bar'],
            'admin in middle of path' => ['/safe/admin/path'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedPaths(): array
    {
        return [
            'exact /admin' => ['/admin', 'firewall-protected prefix'],
            '/admin/login' => ['/admin/login', 'firewall-protected prefix'],
            'case-folded /Admin' => ['/Admin/foo', 'firewall-protected prefix'],
            'all caps /ADMIN' => ['/ADMIN', 'firewall-protected prefix'],
            'leading whitespace' => [' /admin', 'firewall-protected prefix'],
            'trailing whitespace' => ['/admin ', 'firewall-protected prefix'],
            'single URL-encoded' => ['%2Fadmin', 'firewall-protected prefix'],
            'single URL-encoded with subpath' => ['%2Fadmin/login', 'firewall-protected prefix'],
            'double URL-encoded' => ['%252F%2561dmin', 'firewall-protected prefix'],
            'parent-directory traversal' => ['/healthz/../admin/foo', 'parent-directory segment'],
            'pure traversal' => ['/safe/..', 'parent-directory segment'],
            'leading traversal' => ['/../admin', 'parent-directory segment'],
            'no leading slash' => ['admin/foo', 'absolute path'],
            'empty string after trim' => ['   ', 'absolute path'],
        ];
    }

    #[DataProvider('allowedPaths')]
    public function testAllowedPathsCompile(string $path): void
    {
        $extension = new EgstonPimcoreHealthCheckExtension();
        $container = new ContainerBuilder();

        $extension->load([['graphql_internal' => [
            'enabled' => true,
            'endpoints' => [['name' => 'test', 'path' => $path]],
        ]]], $container);

        // Reaching here means load() did not throw — the contract under test.
        $this->assertTrue(
            $container->hasDefinition('egston_pimcore_health_check.graphql_internal.test'),
            'Expected service definition for allowed path.'
        );
    }

    #[DataProvider('rejectedPaths')]
    public function testRejectedPathsThrow(string $path, string $messageFragment): void
    {
        $extension = new EgstonPimcoreHealthCheckExtension();
        $container = new ContainerBuilder();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($messageFragment, '/') . '/');

        $extension->load([['graphql_internal' => [
            'enabled' => true,
            'endpoints' => [['name' => 'test', 'path' => $path]],
        ]]], $container);
    }
}
