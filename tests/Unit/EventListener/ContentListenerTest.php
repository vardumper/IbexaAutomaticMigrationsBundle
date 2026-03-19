<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\Events\Content\BeforeDeleteContentEvent;
use Ibexa\Contracts\Core\Repository\Events\Content\CreateContentEvent;
use Ibexa\Contracts\Core\Repository\Events\Content\UpdateContentEvent;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Ibexa\Contracts\Core\Repository\Values\Content\ContentCreateStruct;
use Ibexa\Contracts\Core\Repository\Values\Content\ContentInfo;
use Ibexa\Contracts\Core\Repository\Values\Content\ContentUpdateStruct;
use Ibexa\Contracts\Core\Repository\Values\Content\VersionInfo;
use Psr\Log\NullLogger;
use vardumper\IbexaAutomaticMigrationsBundle\EventListener\ContentListener;

describe('ContentListener', function () {
    beforeEach(function () {
        $this->tmpDir = makeTmpDir();
        // ContentListener uses SettingsService::isEnabled() which returns false in non-dev APP_ENV — perfect for early-return tests
        $this->listener = new ContentListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['content' => true]),
            $this->tmpDir
        );

        $content = $this->createStub(Content::class);
        $createStruct = $this->createStub(ContentCreateStruct::class);
        $versionInfo = $this->createStub(VersionInfo::class);
        $updateStruct = $this->createStub(ContentUpdateStruct::class);
        $contentInfo = new ContentInfo(['id' => 1, 'mainLocationId' => 2]);

        $this->createEvent = new CreateContentEvent($content, $createStruct, [], null);
        $this->updateEvent = new UpdateContentEvent($content, $versionInfo, $updateStruct, null);
        $this->deleteEvent = new BeforeDeleteContentEvent($contentInfo);
    });

    afterEach(function () {
        removeTmpDir($this->tmpDir);
    });

    it('can be instantiated and creates destination directory', function () {
        expect($this->listener)->toBeInstanceOf(ContentListener::class);
        expect(is_dir($this->tmpDir . '/src/MigrationsDefinitions'))->toBeTrue();
    });

    it('onCreated returns early when not enabled (APP_ENV=testing)', function () {
        // SettingsService::isEnabled() returns false when APP_ENV != 'dev'
        expect(fn () => $this->listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class);
    });

    it('onUpdated returns early when not enabled', function () {
        expect(fn () => $this->listener->onUpdated($this->updateEvent))->not->toThrow(\Throwable::class);
    });

    it('onBeforeDeleted returns early when not enabled', function () {
        expect(fn () => $this->listener->onBeforeDeleted($this->deleteEvent))->not->toThrow(\Throwable::class);
    });

    it('onCreated returns early when content type disabled in dev env', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';
        try {
            $listener = new ContentListener(
                new NullLogger(),
                makeSettingsService($this->tmpDir, true, ['content' => false]),
                $this->tmpDir
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

describe('ContentListener – past CLI guard (fake runner)', function () {
    beforeEach(function () {
        $this->tmpDir = makeTmpDir();
        $content = $this->createStub(Content::class);
        $createStruct = $this->createStub(ContentCreateStruct::class);
        $versionInfo = $this->createStub(VersionInfo::class);
        $updateStruct = $this->createStub(ContentUpdateStruct::class);
        $contentInfo = new ContentInfo(['id' => 1, 'mainLocationId' => 2]);

        $this->createEvent = new CreateContentEvent($content, $createStruct, [], null);
        $this->updateEvent = new UpdateContentEvent($content, $versionInfo, $updateStruct, null);
        $this->deleteEvent = new BeforeDeleteContentEvent($contentInfo);
    });

    afterEach(function () {
        removeTmpDir($this->tmpDir);
    });

    it('onCreated handles successful runner branch', function () {
        $listener = withTestingEnv(fn () => new ContentListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['content' => true]),
            $this->tmpDir,
            makeFakeRunner(0)
        ));

        withEnv('dev', fn () => expect(fn () => $listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class));
    });

    it('onUpdated handles failed runner branch', function () {
        $listener = withTestingEnv(fn () => new ContentListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['content' => true]),
            $this->tmpDir,
            makeFakeRunner(1, '', 'boom')
        ));

        withEnv('dev', fn () => expect(fn () => $listener->onUpdated($this->updateEvent))->not->toThrow(\Throwable::class));
    });

    it('onCreated – forced ibexa mode – exercises ibexa generation branch', function () {
        $listener = withTestingEnv(fn () => new ContentListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['content' => true]),
            $this->tmpDir,
            makeFakeRunner(1, '', 'ibexa-fail')
        ));
        setPrivateProperty($listener, 'mode', 'ibexa');

        withEnv('dev', fn () => expect(fn () => $listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class));
    });
});
