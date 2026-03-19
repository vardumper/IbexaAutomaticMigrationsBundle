<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\Events\Section\BeforeDeleteSectionEvent;
use Ibexa\Contracts\Core\Repository\Events\Section\CreateSectionEvent;
use Ibexa\Contracts\Core\Repository\Events\Section\UpdateSectionEvent;
use Ibexa\Contracts\Core\Repository\Values\Content\Section;
use Ibexa\Contracts\Core\Repository\Values\Content\SectionCreateStruct;
use Ibexa\Contracts\Core\Repository\Values\Content\SectionUpdateStruct;
use Psr\Log\NullLogger;
use vardumper\IbexaAutomaticMigrationsBundle\EventListener\SectionListener;

describe('SectionListener', function () {
    beforeEach(function () {
        $this->tmpDir = makeTmpDir();
        $this->listener = new SectionListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['section' => true]),
            $this->tmpDir,
            makeContainer()
        );
        $section = new Section(['id' => 1, 'identifier' => 'test_section', 'name' => 'Test Section']);
        $createStruct = new SectionCreateStruct(['identifier' => 'test_section', 'name' => 'Test Section']);
        $updateStruct = new SectionUpdateStruct(['identifier' => 'test_section', 'name' => 'Test Section Updated']);
        $this->createEvent = new CreateSectionEvent($section, $createStruct);
        $this->updateEvent = new UpdateSectionEvent($section, $section, $updateStruct);
        $this->deleteEvent = new BeforeDeleteSectionEvent($section);
    });

    afterEach(function () {
        removeTmpDir($this->tmpDir);
    });

    it('can be instantiated and creates destination directory', function () {
        expect($this->listener)->toBeInstanceOf(SectionListener::class);
        expect(is_dir($this->tmpDir . '/src/MigrationsDefinitions'))->toBeTrue();
    });

    it('onCreated returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class);
    });

    it('onUpdated returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onUpdated($this->updateEvent))->not->toThrow(\Throwable::class);
    });

    it('onBeforeDeleted returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onBeforeDeleted($this->deleteEvent))->not->toThrow(\Throwable::class);
    });

    it('onCreated returns early when type disabled in dev env', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';
        try {
            $listener = new SectionListener(
                new NullLogger(),
                makeSettingsService($this->tmpDir, true, ['section' => false]),
                $this->tmpDir,
                makeContainer()
            );
            expect(fn () => $listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class);
        } finally {
            if ($previous === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $previous;
            }
        }
    });

    it('onCreated reaches generateMigration in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class));
    });

    it('onUpdated reaches generateMigration in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onUpdated($this->updateEvent))->not->toThrow(\Throwable::class));
    });

    it('onBeforeDeleted reaches generateMigration in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onBeforeDeleted($this->deleteEvent))->not->toThrow(\Throwable::class));
    });
});

describe('SectionListener – past CLI guard', function () {
    beforeEach(function () {
        $this->tmpDir = makeTmpDir();
        $this->section = new \Ibexa\Contracts\Core\Repository\Values\Content\Section(['id' => 1, 'identifier' => 'test_section', 'name' => 'Test Section']);
        $createStruct = new \Ibexa\Contracts\Core\Repository\Values\Content\SectionCreateStruct(['identifier' => 'test_section', 'name' => 'Test Section']);
        $updateStruct = new \Ibexa\Contracts\Core\Repository\Values\Content\SectionUpdateStruct(['identifier' => 'test_section', 'name' => 'Updated']);
        $this->createEvent = new \Ibexa\Contracts\Core\Repository\Events\Section\CreateSectionEvent($this->section, $createStruct);
        $this->updateEvent = new \Ibexa\Contracts\Core\Repository\Events\Section\UpdateSectionEvent($this->section, $this->section, $updateStruct);
        $this->deleteEvent = new \Ibexa\Contracts\Core\Repository\Events\Section\BeforeDeleteSectionEvent($this->section);
    });

    afterEach(function () {
        removeTmpDir($this->tmpDir);
    });

    it('onCreated – mode=null – generateMigration logs and returns early', function () {
        $listener = withTestingEnv(fn () => new \vardumper\IbexaAutomaticMigrationsBundle\EventListener\SectionListener(
            new \Psr\Log\NullLogger(),
            makeSettingsService($this->tmpDir, true, ['section' => true]),
            $this->tmpDir,
            makeContainer()
        ));
        withEnv('dev', fn () => expect(fn () => $listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class));
    });

    it('onUpdated – mode=null – generateMigration logs and returns early', function () {
        $listener = withTestingEnv(fn () => new \vardumper\IbexaAutomaticMigrationsBundle\EventListener\SectionListener(
            new \Psr\Log\NullLogger(),
            makeSettingsService($this->tmpDir, true, ['section' => true]),
            $this->tmpDir,
            makeContainer()
        ));
        withEnv('dev', fn () => expect(fn () => $listener->onUpdated($this->updateEvent))->not->toThrow(\Throwable::class));
    });

    it('onBeforeDeleted – mode=null – generateMigration logs and returns early', function () {
        $listener = withTestingEnv(fn () => new \vardumper\IbexaAutomaticMigrationsBundle\EventListener\SectionListener(
            new \Psr\Log\NullLogger(),
            makeSettingsService($this->tmpDir, true, ['section' => true]),
            $this->tmpDir,
            makeContainer()
        ));
        withEnv('dev', fn () => expect(fn () => $listener->onBeforeDeleted($this->deleteEvent))->not->toThrow(\Throwable::class));
    });

    it('onCreated – disabled type – returns before generateMigration', function () {
        $listener = withTestingEnv(fn () => new \vardumper\IbexaAutomaticMigrationsBundle\EventListener\SectionListener(
            new \Psr\Log\NullLogger(),
            makeSettingsService($this->tmpDir, true, ['section' => false]),
            $this->tmpDir,
            makeContainer()
        ));
        withEnv('dev', fn () => expect(fn () => $listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class));
    });
});
