<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;
use vardumper\IbexaAutomaticMigrationsBundle\DependencyInjection\IbexaAutomaticMigrationsExtension;

describe('IbexaAutomaticMigrationsExtension', function () {
    it('can be instantiated', function () {
        expect(new IbexaAutomaticMigrationsExtension())->toBeInstanceOf(IbexaAutomaticMigrationsExtension::class);
    });

    it('prepend adds twig paths config', function () {
        $container = new ContainerBuilder();
        $ext = new IbexaAutomaticMigrationsExtension();

        $ext->prepend($container);

        $twigConfig = $container->getExtensionConfig('twig');

        expect($twigConfig)->not->toBeEmpty();
        expect($twigConfig[0])->toHaveKey('paths');
        expect(reset($twigConfig[0]['paths']))->toBe('IbexaAutomaticMigrationsBundle');
    });

    it('prepend registers the bundle views directory in twig paths', function () {
        $container = new ContainerBuilder();
        $ext = new IbexaAutomaticMigrationsExtension();

        $ext->prepend($container);

        $twigConfig = $container->getExtensionConfig('twig');
        $paths = $twigConfig[0]['paths'];

        $registeredPath = key($paths);
        expect(str_contains($registeredPath, 'Resources/views'))->toBeTrue();
    });

    it('load sets ibexa_automatic_migrations parameters', function () {
        $container = new ContainerBuilder();
        $ext = new IbexaAutomaticMigrationsExtension();

        $ext->load([[]], $container);

        expect($container->hasParameter('ibexa_automatic_migrations.enabled'))->toBeTrue();
        expect($container->hasParameter('ibexa_automatic_migrations.types'))->toBeTrue();
    });

    it('load registers settings service in the container', function () {
        $container = new ContainerBuilder();
        $ext = new IbexaAutomaticMigrationsExtension();

        $ext->load([[]], $container);

        expect($container->hasDefinition('ibexa_automatic_migrations.settings_service'))->toBeTrue();
    });
});
