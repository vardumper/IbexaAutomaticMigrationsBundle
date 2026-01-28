<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ReplaceContentTypeServicePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!class_exists('\\Ibexa\\Bundle\\Migration\\IbexaMigrationBundle')  /* ibexa/migrations */
            && !class_exists('\\Kaliop\\IbexaMigrationBundle\\IbexaMigrationBundle') /* mrk-te/ibexa-migration-bundle2 backwards-compatibility with ^2.x */
            && !class_exists('\\Kaliop\\IbexaMigrationBundle\\KaliopMigrationBundle')) { /* mrk-te/ibexa-migration-bundle current ^3.x */
            error_log('unable to activate IbexaAutomaticMigrationsBundle because neither ibexa/migrations nor kaliop/ibexa-migration-bundle is installed. Please install one of them.');
            return;
        }
    }
}