<?php

namespace Egston\PimcoreHealthCheckBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;
use Egston\PimcoreHealthCheckBundle\Health\HealthCheckInterface;
use Egston\PimcoreHealthCheckBundle\Health\GraphQlEndpointCheck;
use Egston\PimcoreHealthCheckBundle\Health\GraphQlSubRequestCheck;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class EgstonPimcoreHealthCheckExtension extends ConfigurableExtension implements PrependExtensionInterface
{
    /**
     * Operator-misconfiguration guard, not a security boundary. The path: value
     * comes from the host's bundle config — there's no untrusted input flowing
     * here, so we're not defending against attackers; we're catching the
     * highest-likelihood typo. The host's security.yaml is the source of
     * truth for what's actually firewall-protected.
     *
     * Only /admin is listed because it's the one prefix every Pimcore
     * deployment shares. Other firewall-protected prefixes (e.g. /api/* under
     * pimcore-jwt-auth-bundle, /asset/webdav, custom host firewalls) vary by
     * installation; encoding them here would couple this bundle to sibling
     * bundles' routing choices and drift the moment those choices change.
     * Operator competence + the README security caveat carry the rest.
     */
    private const SUB_REQUEST_DENIED_PATH_PREFIXES = [
        '/admin',
    ];

    public function loadInternal(array $mergedConfig, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');

        $container->setParameter('egston_pimcore_health_check.enabled', $mergedConfig['enabled']);
        $container->setParameter('egston_pimcore_health_check.path', $mergedConfig['path']);

        $container->registerForAutoconfiguration(HealthCheckInterface::class)
            ->addTag('egston.pimcore_health_check.check');

        $this->registerGraphQlChecks($mergedConfig['graphql'] ?? [], $container);
        $this->registerGraphQlInternalChecks($mergedConfig['graphql_internal'] ?? [], $container);
    }

    public function prepend(ContainerBuilder $container): void
    {
        // expose bundle configuration defaults to Pimcore admin UI if needed later
    }

    /**
     * @param array<string, mixed> $graphqlConfig
     */
    private function registerGraphQlChecks(array $graphqlConfig, ContainerBuilder $container): void
    {
        if (empty($graphqlConfig['enabled']) || empty($graphqlConfig['endpoints'])) {
            return;
        }

        $seenIds = [];
        foreach ($graphqlConfig['endpoints'] as $endpointConfig) {
            $resolved = $this->resolveGraphQlEndpointConfig($endpointConfig);

            $serviceId = sprintf(
                'egston_pimcore_health_check.graphql.%s',
                $this->normalizeServiceIdSegment($endpointConfig['name'])
            );
            if (isset($seenIds[$serviceId])) {
                throw new \InvalidArgumentException(sprintf(
                    'GraphQL endpoints "%s" and "%s" normalize to the same service ID "%s"; rename one so they differ in alphanumeric characters.',
                    $seenIds[$serviceId],
                    $endpointConfig['name'],
                    $serviceId
                ));
            }
            $seenIds[$serviceId] = $endpointConfig['name'];

            $definition = (new Definition(GraphQlEndpointCheck::class))
                ->setAutowired(true)
                ->setAutoconfigured(false)
                ->setArgument('$logger', new Reference('logger'))
                ->setArgument('$name', $endpointConfig['name'])
                ->setArgument('$url', $resolved['url'])
                ->setArgument('$query', $endpointConfig['query'])
                ->setArgument('$timeout', (float) $endpointConfig['timeout'])
                ->setArgument('$headers', $resolved['headers'])
                ->setArgument('$assertions', $resolved['assertions'])
                ->setArgument('$apiKey', $resolved['api_key'])
                ->setArgument('$authorizationBearer', $resolved['authorization_bearer'])
                ->addTag('egston.pimcore_health_check.check');

            $container->setDefinition($serviceId, $definition);
        }
    }

    /**
     * @param array<string, mixed> $endpointConfig
     * @return array{url:string, headers:array, assertions:array, api_key:?string, authorization_bearer:?string}
     */
    private function resolveGraphQlEndpointConfig(array $endpointConfig): array
    {
        return [
            'url' => $endpointConfig['url'],
            'headers' => $endpointConfig['headers'] ?? [],
            'assertions' => $endpointConfig['assert'] ?? [],
            'api_key' => $endpointConfig['api_key'] ?? null,
            'authorization_bearer' => $endpointConfig['authorization_bearer'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $graphqlInternalConfig
     */
    private function registerGraphQlInternalChecks(array $graphqlInternalConfig, ContainerBuilder $container): void
    {
        if (empty($graphqlInternalConfig['enabled']) || empty($graphqlInternalConfig['endpoints'])) {
            return;
        }

        $seenIds = [];
        foreach ($graphqlInternalConfig['endpoints'] as $endpointConfig) {
            $this->assertSubRequestPathSafe($endpointConfig['name'], $endpointConfig['path']);

            $serviceId = sprintf(
                'egston_pimcore_health_check.graphql_internal.%s',
                $this->normalizeServiceIdSegment($endpointConfig['name'])
            );
            if (isset($seenIds[$serviceId])) {
                throw new \InvalidArgumentException(sprintf(
                    'GraphQL internal endpoints "%s" and "%s" normalize to the same service ID "%s"; rename one so they differ in alphanumeric characters.',
                    $seenIds[$serviceId],
                    $endpointConfig['name'],
                    $serviceId
                ));
            }
            $seenIds[$serviceId] = $endpointConfig['name'];

            $definition = (new Definition(GraphQlSubRequestCheck::class))
                ->setAutowired(false)
                ->setAutoconfigured(false)
                ->setArgument('$kernel', new Reference(HttpKernelInterface::class))
                ->setArgument('$logger', new Reference('logger'))
                ->setArgument('$name', $endpointConfig['name'])
                ->setArgument('$path', $endpointConfig['path'])
                ->setArgument('$query', $endpointConfig['query'])
                ->setArgument('$timeout', (float) $endpointConfig['timeout'])
                ->setArgument('$assertions', $endpointConfig['assert'] ?? [])
                ->setArgument('$apiKey', $endpointConfig['api_key'] ?? null)
                ->addTag('egston.pimcore_health_check.check');

            $container->setDefinition($serviceId, $definition);
        }
    }

    /**
     * SUB_REQUEST bypasses Symfony's firewall and access_control, so a probe
     * pointed at /admin or /api/* would reach its controller unauthenticated.
     * Configuration cannot express this content policy — enforced at compile time.
     *
     * Normalization steps (each closes a known bypass shape):
     *   - trim — leading/trailing whitespace in the YAML scalar would otherwise
     *     defeat str_starts_with against bare prefixes
     *   - bounded urldecode loop — defeats double-encoded escapes like %2561dmin
     *   - require leading '/' after decode — Symfony Request::create with a
     *     missing slash routes ambiguously
     *   - reject '..' as a path segment — '/safe/../admin' would resolve into
     *     a firewall-protected route at routing time
     *   - prefix anchored at path boundary (exact match or prefix + '/') —
     *     '/administrator' should not match the '/admin' rule but '/admin/x'
     *     and '/admin' (exact) must
     */
    private function assertSubRequestPathSafe(string $name, string $path): void
    {
        $normalized = trim($path);
        for ($i = 0; $i < 4; $i++) {
            $next = urldecode($normalized);
            if ($next === $normalized) {
                break;
            }
            $normalized = $next;
        }
        $normalized = strtolower($normalized);

        if (!str_starts_with($normalized, '/')) {
            throw new \InvalidArgumentException(sprintf(
                'GraphQL internal endpoint "%s" path "%s" must be an absolute path beginning with "/" (after URL-decoding).',
                $name,
                $path
            ));
        }

        if (in_array('..', explode('/', $normalized), true)) {
            throw new \InvalidArgumentException(sprintf(
                'GraphQL internal endpoint "%s" path "%s" contains a parent-directory segment ("..") which can resolve into firewall-protected routes.',
                $name,
                $path
            ));
        }

        foreach (self::SUB_REQUEST_DENIED_PATH_PREFIXES as $prefix) {
            if ($normalized === $prefix || str_starts_with($normalized, $prefix . '/')) {
                throw new \InvalidArgumentException(sprintf(
                    'GraphQL internal endpoint "%s" path "%s" targets a firewall-protected prefix ("%s"). SUB_REQUEST bypasses Symfony firewalls; configure only routes intended for unauthenticated access.',
                    $name,
                    $path,
                    $prefix
                ));
            }
        }
    }

    private function normalizeServiceIdSegment(string $name): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $name) ?? 'default';

        return strtolower($slug);
    }
}
