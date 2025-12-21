<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ReplaceContentTypeServicePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!class_exists('\\Ibexa\\Bundle\\Migration\\IbexaMigrationBundle') && !class_exists('\\Kaliop\\IbexaMigrationBundle\\IbexaMigrationBundle')) {
            error_log('unable to activate ContentTypeMigrationsBundle because neither ibexa/migrations nor kaliop/ibexa-migration-bundle is installed. Please install one of them.');
            return;
        }
    }
}
