<?php

namespace Egston\PimcoreHealthCheckBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('egston_pimcore_health_check');

        $treeBuilder->getRootNode()
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                ->end()
                ->scalarNode('path')
                    ->defaultValue('/healthz')
                    ->info('URL path for the health check endpoint.')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
