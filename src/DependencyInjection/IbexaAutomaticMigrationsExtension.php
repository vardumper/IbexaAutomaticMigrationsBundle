<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class IbexaAutomaticMigrationsExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('ibexa_automatic_migrations.enabled', $config['enabled']);
        $container->setParameter('ibexa_automatic_migrations.types', $config['types']);
    }

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('twig', [
            'paths' => [
                __DIR__ . '/../Resources/views' => 'IbexaAutomaticMigrationsBundle',
            ],
        ]);
    }
}
