<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class ReplaceContentTypeServicePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!class_exists('\\Ibexa\\Bundle\\Migration\\IbexaMigrationBundle') && !class_exists('\\Kaliop\\IbexaMigrationBundle\\IbexaMigrationBundle')) {
            error_log('unable to activate ContentTypeMigrationsBundle because neither ibexa/migrations nor kaliop/ibexa-migration-bundle is installed. Please install one of them.');
            return;
        }

        if (! $container->hasAlias('ibexa.api.service.content_type') && ! $container->hasDefinition('ibexa.api.service.content_type')) {
            return;
        }

        $originalId = null;
        if ($container->hasAlias('ibexa.api.service.content_type')) {
            $alias = $container->getAlias('ibexa.api.service.content_type');
            $originalId = (string) $alias;
        } elseif ($container->hasDefinition('ibexa.api.service.content_type')) {
            $originalId = 'ibexa.api.service.content_type';
        }

        if (! $originalId) {
            return;
        }

        if (! $container->hasDefinition($originalId) && ! $container->hasAlias($originalId) && ! $container->has($originalId)) {
            return;
        }

        $decoratorServiceId = 'content_type_migrations.content_type_service_decorator';

        if (! $container->hasDefinition($decoratorServiceId) && ! $container->hasAlias($decoratorServiceId)) {
            $def = new Definition('vardumper\\IbexaAutomaticMigrationsBundle\\Service\\ContentTypeServiceDecorator');
            $def->setPublic(true);
            $def->setDecoratedService($originalId);
            $def->setArguments([
                new Reference('event_dispatcher'),
                new Reference('logger'),
            ]);
            $container->setDefinition($decoratorServiceId, $def);
        }
    }
}
