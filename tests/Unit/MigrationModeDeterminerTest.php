<?php

declare(strict_types=1);

use vardumper\IbexaAutomaticMigrationsBundle\Service\MigrationModeDeterminer;

describe('MigrationModeDeterminer', function () {
    it('returns create if identifier not found by service', function () {
        $draft = new class() {
            public string $identifier = 'new_type';
        };
        $service = new class() {
            public function loadContentTypeByIdentifier($identifier)
            {
                return null;
            }
        };
        $determiner = new MigrationModeDeterminer();
        expect($determiner->determineCreateOrUpdateMode($draft, $service))->toBe('create');
    });

    it('returns update if identifier found by service', function () {
        $draft = new class() {
            public string $identifier = 'existing_type';
        };
        $service = new class() {
            public function loadContentTypeByIdentifier($identifier)
            {
                return (object)['id' => 1];
            }
        };
        $determiner = new MigrationModeDeterminer();
        expect($determiner->determineCreateOrUpdateMode($draft, $service))->toBe('update');
    });

    it('returns update if identifier is null', function () {
        $draft = new class() {};
        $service = new class() {
            public function loadContentTypeByIdentifier($identifier)
            {
                return null;
            }
        };
        $determiner = new MigrationModeDeterminer();
        expect($determiner->determineCreateOrUpdateMode($draft, $service))->toBe('update');
    });

    it('returns update if service throws', function () {
        $draft = new class() {
            public string $identifier = 'error_type';
        };
        $service = new class() {
            public function loadContentTypeByIdentifier($identifier)
            {
                throw new Exception('fail');
            }
        };
        $determiner = new MigrationModeDeterminer();
        expect($determiner->determineCreateOrUpdateMode($draft, $service))->toBe('update');
    });
});
