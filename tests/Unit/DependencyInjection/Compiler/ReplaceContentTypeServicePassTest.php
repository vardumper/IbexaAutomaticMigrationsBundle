<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;
use vardumper\IbexaAutomaticMigrationsBundle\DependencyInjection\Compiler\ReplaceContentTypeServicePass;

describe('ReplaceContentTypeServicePass', function () {
    it('can be instantiated', function () {
        expect(new ReplaceContentTypeServicePass())->toBeInstanceOf(ReplaceContentTypeServicePass::class);
    });

    it('process does not throw when a migration bundle is present', function () {
        $pass = new ReplaceContentTypeServicePass();
        $container = new ContainerBuilder();

        // Kaliop bundle is in vendor — process() should not throw
        expect(fn () => $pass->process($container))->not->toThrow(\Throwable::class);
    });

    it('implements CompilerPassInterface', function () {
        expect(new ReplaceContentTypeServicePass())
            ->toBeInstanceOf(\Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface::class);
    });
});
