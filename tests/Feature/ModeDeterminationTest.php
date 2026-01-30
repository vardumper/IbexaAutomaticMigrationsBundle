<?php

use vardumper\IbexaAutomaticMigrationsBundle\Service\MigrationModeDeterminer;

describe('MigrationModeDeterminer (feature)', function () {
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
});
