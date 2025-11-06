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

class EgstonPimcoreHealthCheckExtension extends ConfigurableExtension implements PrependExtensionInterface
{
    public function loadInternal(array $mergedConfig, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');

        $container->setParameter('egston_pimcore_health_check.enabled', $mergedConfig['enabled']);
        $container->setParameter('egston_pimcore_health_check.path', $mergedConfig['path']);

        $container->registerForAutoconfiguration(HealthCheckInterface::class)
            ->addTag('egston.pimcore_health_check.check');

        $this->registerGraphQlChecks($mergedConfig['graphql'] ?? [], $container);
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

        foreach ($graphqlConfig['endpoints'] as $endpointConfig) {
            $resolved = $this->resolveGraphQlEndpointConfig($endpointConfig);

            $serviceId = sprintf(
                'egston_pimcore_health_check.graphql.%s',
                $this->normalizeServiceIdSegment($endpointConfig['name'])
            );

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
        $url = $endpointConfig['url'] ?? null;
        $apiKey = $endpointConfig['api_key'] ?? null;
        $headers = $endpointConfig['headers'] ?? [];
        $assertions = $endpointConfig['assert'] ?? [];
        $authorizationBearer = $endpointConfig['authorization_bearer'] ?? null;

        if ($url === null) {
            throw new \InvalidArgumentException(sprintf(
                'GraphQL endpoint "%s" requires a "url" configuration value.',
                $endpointConfig['name'] ?? '[unnamed]'
            ));
        }

        return [
            'url' => $url,
            'headers' => $headers,
            'assertions' => $assertions,
            'api_key' => $apiKey,
            'authorization_bearer' => $authorizationBearer,
        ];
    }

    private function normalizeServiceIdSegment(string $name): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $name) ?? 'default';

        return strtolower($slug);
    }
}
