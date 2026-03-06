<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;
use vardumper\IbexaAutomaticMigrationsBundle\DependencyInjection\Compiler\ReplaceContentTypeServicePass;
use vardumper\IbexaAutomaticMigrationsBundle\IbexaAutomaticMigrationsBundle;

describe('IbexaAutomaticMigrationsBundle', function () {
    it('can be instantiated', function () {
        expect(new IbexaAutomaticMigrationsBundle())->toBeInstanceOf(IbexaAutomaticMigrationsBundle::class);
    });

    it('extends Symfony Bundle', function () {
        expect(new IbexaAutomaticMigrationsBundle())
            ->toBeInstanceOf(\Symfony\Component\HttpKernel\Bundle\Bundle::class);
    });

    it('getPath returns the bundle root directory', function () {
        $bundle = new IbexaAutomaticMigrationsBundle();
        $path = $bundle->getPath();

        expect(is_dir($path))->toBeTrue();
        expect(str_ends_with(rtrim($path, '/'), 'IbexaAutomaticMigrationsBundle'))->toBeTrue();
    });

    it('build adds the ReplaceContentTypeServicePass compiler pass', function () {
        $bundle = new IbexaAutomaticMigrationsBundle();
        $container = new ContainerBuilder();

        $bundle->build($container);

        $passes = $container->getCompilerPassConfig()->getPasses();
        $passClasses = array_map(fn ($p) => get_class($p), $passes);

        expect($passClasses)->toContain(ReplaceContentTypeServicePass::class);
    });

    it('build registers twig paths', function () {
        $bundle = new IbexaAutomaticMigrationsBundle();
        $container = new ContainerBuilder();

        $bundle->build($container);

        $twigConfig = $container->getExtensionConfig('twig');

        expect($twigConfig)->not->toBeEmpty();
        expect(reset($twigConfig[0]['paths']))->toBe('IbexaAutomaticMigrationsBundle');
    });
});
