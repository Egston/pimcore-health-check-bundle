<?php

namespace Egston\PimcoreHealthCheckBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;
use Egston\PimcoreHealthCheckBundle\Health\HealthCheckInterface;

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
    }

    public function prepend(ContainerBuilder $container): void
    {
        // expose bundle configuration defaults to Pimcore admin UI if needed later
    }
}
