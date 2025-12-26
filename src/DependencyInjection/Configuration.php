<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('ibexa_automatic_migrations');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
            ->booleanNode('enabled')
            ->defaultTrue()
            ->end()
            ->arrayNode('types')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('content_type')
            ->defaultTrue()
            ->end()
            ->booleanNode('content_type_group')
            ->defaultTrue()
            ->end()
            ->booleanNode('section')
            ->defaultFalse()
            ->end()
            ->booleanNode('object_state')
            ->defaultFalse()
            ->end()
            ->booleanNode('object_state_group')
            ->defaultFalse()
            ->end()
            ->booleanNode('user')
            ->defaultFalse()
            ->end()
            ->booleanNode('user_group')
            ->defaultFalse()
            ->end()
            ->booleanNode('role')
            ->defaultFalse()
            ->end()
            ->booleanNode('language')
            ->defaultFalse()
            ->end()
            ->booleanNode('url')
            ->defaultFalse()
            ->end()
            ->end()
            ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
