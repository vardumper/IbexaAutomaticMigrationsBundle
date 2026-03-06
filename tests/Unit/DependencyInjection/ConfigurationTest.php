<?php

declare(strict_types=1);

use Symfony\Component\Config\Definition\Processor;
use vardumper\IbexaAutomaticMigrationsBundle\DependencyInjection\Configuration;

describe('Configuration', function () {
    it('returns a TreeBuilder instance', function () {
        $config = new Configuration();
        $tree = $config->getConfigTreeBuilder();

        expect($tree)->toBeInstanceOf(\Symfony\Component\Config\Definition\Builder\TreeBuilder::class);
    });

    it('root node is named ibexa_automatic_migrations', function () {
        $config = new Configuration();
        $tree = $config->getConfigTreeBuilder();

        expect($tree->buildTree()->getName())->toBe('ibexa_automatic_migrations');
    });

    it('processes empty config and returns all defaults', function () {
        $processor = new Processor();
        $config = new Configuration();

        $result = $processor->processConfiguration($config, []);

        expect($result['enabled'])->toBeTrue();
        expect($result['types'])->toBeArray();
    });

    it('default enabled is true', function () {
        $processor = new Processor();
        $result = $processor->processConfiguration(new Configuration(), []);

        expect($result['enabled'])->toBeTrue();
    });

    it('default content_type is true', function () {
        $processor = new Processor();
        $result = $processor->processConfiguration(new Configuration(), []);

        expect($result['types']['content_type'])->toBeTrue();
    });

    it('default content_type_group is true', function () {
        $processor = new Processor();
        $result = $processor->processConfiguration(new Configuration(), []);

        expect($result['types']['content_type_group'])->toBeTrue();
    });

    it('default content is false', function () {
        $processor = new Processor();
        $result = $processor->processConfiguration(new Configuration(), []);

        expect($result['types']['content'])->toBeFalse();
    });

    it('default section is false', function () {
        $processor = new Processor();
        $result = $processor->processConfiguration(new Configuration(), []);

        expect($result['types']['section'])->toBeFalse();
    });

    it('default object_state is false', function () {
        $processor = new Processor();
        $result = $processor->processConfiguration(new Configuration(), []);

        expect($result['types']['object_state'])->toBeFalse();
    });

    it('default object_state_group is false', function () {
        $processor = new Processor();
        $result = $processor->processConfiguration(new Configuration(), []);

        expect($result['types']['object_state_group'])->toBeFalse();
    });

    it('default user is false', function () {
        $processor = new Processor();
        $result = $processor->processConfiguration(new Configuration(), []);

        expect($result['types']['user'])->toBeFalse();
    });

    it('default role is false', function () {
        $processor = new Processor();
        $result = $processor->processConfiguration(new Configuration(), []);

        expect($result['types']['role'])->toBeFalse();
    });

    it('default language is false', function () {
        $processor = new Processor();
        $result = $processor->processConfiguration(new Configuration(), []);

        expect($result['types']['language'])->toBeFalse();
    });

    it('default url is false', function () {
        $processor = new Processor();
        $result = $processor->processConfiguration(new Configuration(), []);

        expect($result['types']['url'])->toBeFalse();
    });

    it('accepts enabled set to false', function () {
        $processor = new Processor();
        $result = $processor->processConfiguration(new Configuration(), [
            ['enabled' => false],
        ]);

        expect($result['enabled'])->toBeFalse();
    });

    it('accepts overriding individual type defaults', function () {
        $processor = new Processor();
        $result = $processor->processConfiguration(new Configuration(), [
            ['types' => ['section' => true, 'content_type' => false]],
        ]);

        expect($result['types']['section'])->toBeTrue();
        expect($result['types']['content_type'])->toBeFalse();
    });
});
