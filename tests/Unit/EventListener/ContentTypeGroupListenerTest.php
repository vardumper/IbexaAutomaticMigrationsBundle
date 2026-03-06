<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\Events\ContentType\CreateContentTypeGroupEvent;
use Ibexa\Contracts\Core\Repository\Events\ContentType\DeleteContentTypeGroupEvent;
use Ibexa\Contracts\Core\Repository\Events\ContentType\UpdateContentTypeGroupEvent;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroup;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroupCreateStruct;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroupUpdateStruct;
use Psr\Log\NullLogger;
use vardumper\IbexaAutomaticMigrationsBundle\EventListener\ContentTypeGroupListener;

describe('ContentTypeGroupListener', function () {
    beforeEach(function () {
        $this->tmpDir = makeTmpDir();
        $this->listener = new ContentTypeGroupListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['content_type_group' => true]),
            $this->tmpDir,
            makeContainer()
        );

        $group = $this->createStub(ContentTypeGroup::class);
        $createStruct = new ContentTypeGroupCreateStruct(['identifier' => 'test_group']);
        $updateStruct = new ContentTypeGroupUpdateStruct(['identifier' => 'test_group_updated']);

        $this->createEvent = new CreateContentTypeGroupEvent($group, $createStruct);
        $this->deleteEvent = new DeleteContentTypeGroupEvent($group);
        $this->updateEvent = new UpdateContentTypeGroupEvent($group, $updateStruct);
    });

    afterEach(function () {
        removeTmpDir($this->tmpDir);
    });

    it('can be instantiated and creates destination directory', function () {
        expect($this->listener)->toBeInstanceOf(ContentTypeGroupListener::class);
        expect(is_dir($this->tmpDir . '/src/MigrationsDefinitions'))->toBeTrue();
    });

    it('onCreated returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class);
    });

    it('onDeleted returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onDeleted($this->deleteEvent))->not->toThrow(\Throwable::class);
    });

    it('onUpdated returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onUpdated($this->updateEvent))->not->toThrow(\Throwable::class);
    });

    it('onCreated returns early when content_type_group disabled in dev env', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';
        try {
            $listener = new ContentTypeGroupListener(
                new NullLogger(),
                makeSettingsService($this->tmpDir, true, ['content_type_group' => false]),
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

    it('onCreated stops at isCli check in dev env with enabled settings', function () {
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

    it('onDeleted stops at isCli check in dev env with enabled settings', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';
        try {
            expect(fn () => $this->listener->onDeleted($this->deleteEvent))->not->toThrow(\Throwable::class);
        } finally {
            if ($previous === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $previous;
            }
        }
    });

    it('onUpdated stops at isCli check in dev env with enabled settings', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';
        try {
            expect(fn () => $this->listener->onUpdated($this->updateEvent))->not->toThrow(\Throwable::class);
        } finally {
            if ($previous === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $previous;
            }
        }
    });

    it('returns early when settings service is disabled in dev env', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';
        try {
            $listener = new ContentTypeGroupListener(
                new NullLogger(),
                makeSettingsService($this->tmpDir, false, ['content_type_group' => true]),
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
});
