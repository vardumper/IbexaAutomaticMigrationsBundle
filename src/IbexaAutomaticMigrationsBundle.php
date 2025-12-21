<?php

declare(strict_types=1);

namespace IbexaAutomaticMigrationsBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use IbexaAutomaticMigrationsBundle\DependencyInjection\Compiler\ReplaceContentTypeServicePass;

class IbexaAutomaticMigrationsBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new ReplaceContentTypeServicePass());
    }
}

