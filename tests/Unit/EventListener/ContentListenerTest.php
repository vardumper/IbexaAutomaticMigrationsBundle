<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\Events\Content\BeforeDeleteContentEvent;
use Ibexa\Contracts\Core\Repository\Events\Content\PublishVersionEvent;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Ibexa\Contracts\Core\Repository\Values\Content\ContentInfo;
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
            $this->tmpDir,
            makeContainer()
        );

        $contentInfo = new ContentInfo(['id' => 1, 'mainLocationId' => 2]);
        $content = $this->getMockBuilder(Content::class)
            ->disableOriginalConstructor()
            ->getMock();
        $content->method('__get')->willReturnCallback(fn (string $prop) => match ($prop) {
            'contentInfo' => $contentInfo,
            default => null,
        });

        $versionInfoV1 = $this->getMockBuilder(VersionInfo::class)
            ->disableOriginalConstructor()
            ->getMock();
        $versionInfoV1->method('getVersionNo')->willReturn(1);

        $versionInfoV2 = $this->getMockBuilder(VersionInfo::class)
            ->disableOriginalConstructor()
            ->getMock();
        $versionInfoV2->method('getVersionNo')->willReturn(2);

        $this->publishCreateEvent = new PublishVersionEvent($content, $versionInfoV1, []);
        $this->publishUpdateEvent = new PublishVersionEvent($content, $versionInfoV2, []);
        $this->deleteEvent = new BeforeDeleteContentEvent($contentInfo);
    });

    afterEach(function () {
        removeTmpDir($this->tmpDir);
    });

    it('can be instantiated and creates destination directory', function () {
        expect($this->listener)->toBeInstanceOf(ContentListener::class);
        expect(is_dir($this->tmpDir . '/src/MigrationsDefinitions'))->toBeTrue();
    });

    it('onPublished (create) returns early when not enabled (APP_ENV=testing)', function () {
        // SettingsService::isEnabled() returns false when APP_ENV != 'dev'
        expect(fn () => $this->listener->onPublished($this->publishCreateEvent))->not->toThrow(\Throwable::class);
    });

    it('onPublished (update) returns early when not enabled', function () {
        expect(fn () => $this->listener->onPublished($this->publishUpdateEvent))->not->toThrow(\Throwable::class);
    });

    it('onBeforeDeleted returns early when not enabled', function () {
        expect(fn () => $this->listener->onBeforeDeleted($this->deleteEvent))->not->toThrow(\Throwable::class);
    });

    it('onPublished returns early when content type disabled in dev env', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';
        try {
            $listener = new ContentListener(
                new NullLogger(),
                makeSettingsService($this->tmpDir, true, ['content' => false]),
                $this->tmpDir,
                makeContainer()
            );
            expect(fn () => $listener->onPublished($this->publishCreateEvent))->not->toThrow(\Throwable::class);
        } finally {
            if ($previous === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $previous;
            }
        }
    });

    it('onPublished (create) reaches generateMigration in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onPublished($this->publishCreateEvent))->not->toThrow(\Throwable::class));
    });

    it('onPublished (update) reaches generateMigration in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onPublished($this->publishUpdateEvent))->not->toThrow(\Throwable::class));
    });

    it('onBeforeDeleted reaches generateMigration in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onBeforeDeleted($this->deleteEvent))->not->toThrow(\Throwable::class));
    });
});

describe('ContentListener – past CLI guard (fake runner)', function () {
    beforeEach(function () {
        $this->tmpDir = makeTmpDir();
        $contentInfo = new ContentInfo(['id' => 1, 'mainLocationId' => 2]);
        $content = $this->getMockBuilder(Content::class)
            ->disableOriginalConstructor()
            ->getMock();
        $content->method('__get')->willReturnCallback(fn (string $prop) => match ($prop) {
            'contentInfo' => $contentInfo,
            default => null,
        });

        $versionInfoV1 = $this->getMockBuilder(VersionInfo::class)
            ->disableOriginalConstructor()
            ->getMock();
        $versionInfoV1->method('getVersionNo')->willReturn(1);

        $versionInfoV2 = $this->getMockBuilder(VersionInfo::class)
            ->disableOriginalConstructor()
            ->getMock();
        $versionInfoV2->method('getVersionNo')->willReturn(2);

        $this->publishCreateEvent = new PublishVersionEvent($content, $versionInfoV1, []);
        $this->publishUpdateEvent = new PublishVersionEvent($content, $versionInfoV2, []);
        $this->deleteEvent = new BeforeDeleteContentEvent($contentInfo);
    });

    afterEach(function () {
        removeTmpDir($this->tmpDir);
    });

    it('onPublished (create) handles successful runner branch', function () {
        $listener = withTestingEnv(fn () => new ContentListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['content' => true]),
            $this->tmpDir,
            makeContainer(),
            makeFakeRunner(0)
        ));

        withEnv('dev', fn () => expect(fn () => $listener->onPublished($this->publishCreateEvent))->not->toThrow(\Throwable::class));
    });

    it('onPublished (update) handles failed runner branch', function () {
        $listener = withTestingEnv(fn () => new ContentListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['content' => true]),
            $this->tmpDir,
            makeContainer(),
            makeFakeRunner(1, '', 'boom')
        ));

        withEnv('dev', fn () => expect(fn () => $listener->onPublished($this->publishUpdateEvent))->not->toThrow(\Throwable::class));
    });

    it('onPublished (create) – forced ibexa mode – exercises ibexa generation branch', function () {
        $listener = withTestingEnv(fn () => new ContentListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['content' => true]),
            $this->tmpDir,
            makeContainer(),
            makeFakeRunner(1, '', 'ibexa-fail')
        ));
        setPrivateProperty($listener, 'mode', 'ibexa');

        withEnv('dev', fn () => expect(fn () => $listener->onPublished($this->publishCreateEvent))->not->toThrow(\Throwable::class));
    });
});
