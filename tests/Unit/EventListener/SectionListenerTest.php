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

    it('onCreated returns early at isCli check when dev env with enabled settings', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';
        try {
            expect(fn () => $this->listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class);
        } finally {
            if ($previous === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $previous;
            }
        }
    });
});
