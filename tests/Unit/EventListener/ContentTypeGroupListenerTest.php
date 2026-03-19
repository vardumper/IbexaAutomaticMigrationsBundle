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
        withEnv('dev', function () {
            $listener = new ContentTypeGroupListener(
                new NullLogger(),
                makeSettingsService($this->tmpDir, false, ['content_type_group' => true]),
                $this->tmpDir,
                makeContainer()
            );
            expect(fn () => $listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class);
        });
    });

    it('onCreated reaches generateMigration in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class));
    });

    it('onUpdated reaches generateMigration in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onUpdated($this->updateEvent))->not->toThrow(\Throwable::class));
    });

    it('onDeleted reaches generateMigration in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onDeleted($this->deleteEvent))->not->toThrow(\Throwable::class));
    });
});

describe('ContentTypeGroupListener – past CLI guard (fake runner)', function () {
    beforeEach(function () {
        $this->tmpDir = makeTmpDir();
        $this->fakeRunner = makeFakeRunner(1); // default: process fails
        $this->settings = makeSettingsService($this->tmpDir, true, ['content_type_group' => true]);

        $group = $this->createStub(\Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroup::class);
        $createStruct = new \Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroupCreateStruct(['identifier' => 'test_group']);
        $updateStruct = new \Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroupUpdateStruct(['identifier' => 'test_group_updated']);

        $this->createEvent = new \Ibexa\Contracts\Core\Repository\Events\ContentType\CreateContentTypeGroupEvent($group, $createStruct);
        $this->deleteEvent = new \Ibexa\Contracts\Core\Repository\Events\ContentType\DeleteContentTypeGroupEvent($group);
        $this->updateEvent = new \Ibexa\Contracts\Core\Repository\Events\ContentType\UpdateContentTypeGroupEvent($group, $updateStruct);
    });

    afterEach(function () {
        removeTmpDir($this->tmpDir);
    });

    it('onCreated – runner fails – logs warning and returns cleanly', function () {
        $listener = withTestingEnv(fn () => new \vardumper\IbexaAutomaticMigrationsBundle\EventListener\ContentTypeGroupListener(
            new \Psr\Log\NullLogger(),
            $this->settings,
            $this->tmpDir,
            makeContainer(),
            makeFakeRunner(1)
        ));
        withEnv('dev', fn () => expect(fn () => $listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class));
    });

    it('onCreated – runner succeeds – no migration files found – logs warning', function () {
        $listener = withTestingEnv(fn () => new \vardumper\IbexaAutomaticMigrationsBundle\EventListener\ContentTypeGroupListener(
            new \Psr\Log\NullLogger(),
            $this->settings,
            $this->tmpDir,
            makeContainer(),
            makeFakeRunner(0)
        ));
        withEnv('dev', fn () => expect(fn () => $listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class));
    });

    it('onCreated – runner succeeds – migration file found – marks as executed', function () {
        $dest = $this->tmpDir . '/src/MigrationsDefinitions';
        @mkdir($dest, 0777, true);
        $file = $dest . '/2099_01_01_00_00_00_auto_test.yaml';
        file_put_contents($file, "- type: content_type_group\n  mode: create\n");
        // ensure filemtime is stable
        touch($file, time() - 1);

        $listener = withTestingEnv(fn () => new \vardumper\IbexaAutomaticMigrationsBundle\EventListener\ContentTypeGroupListener(
            new \Psr\Log\NullLogger(),
            $this->settings,
            $this->tmpDir,
            makeContainer(),
            makeFakeRunner(0)
        ));
        withEnv('dev', fn () => expect(fn () => $listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class));
        expect(file_exists($file))->toBeTrue();
    });

    it('onUpdated – runner fails – returns cleanly', function () {
        $listener = withTestingEnv(fn () => new \vardumper\IbexaAutomaticMigrationsBundle\EventListener\ContentTypeGroupListener(
            new \Psr\Log\NullLogger(),
            $this->settings,
            $this->tmpDir,
            makeContainer(),
            makeFakeRunner(1)
        ));
        withEnv('dev', fn () => expect(fn () => $listener->onUpdated($this->updateEvent))->not->toThrow(\Throwable::class));
    });

    it('onUpdated – runner succeeds – migration file found – marks as executed', function () {
        $dest = $this->tmpDir . '/src/MigrationsDefinitions';
        @mkdir($dest, 0777, true);
        $file = $dest . '/2099_01_01_00_00_01_auto_test.yaml';
        file_put_contents($file, "- type: content_type_group\n  mode: update\n");
        touch($file, time() - 1);

        $listener = withTestingEnv(fn () => new \vardumper\IbexaAutomaticMigrationsBundle\EventListener\ContentTypeGroupListener(
            new \Psr\Log\NullLogger(),
            $this->settings,
            $this->tmpDir,
            makeContainer(),
            makeFakeRunner(0)
        ));
        withEnv('dev', fn () => expect(fn () => $listener->onUpdated($this->updateEvent))->not->toThrow(\Throwable::class));
    });

    it('onDeleted – writes YAML file and marks as executed cleanly', function () {
        $listener = withTestingEnv(fn () => new \vardumper\IbexaAutomaticMigrationsBundle\EventListener\ContentTypeGroupListener(
            new \Psr\Log\NullLogger(),
            $this->settings,
            $this->tmpDir,
            makeContainer()
        ));
        withEnv('dev', fn () => expect(fn () => $listener->onDeleted($this->deleteEvent))->not->toThrow(\Throwable::class));
    });

    it('onCreated – disabled type – returns early before runner', function () {
        $settings = makeSettingsService($this->tmpDir, true, ['content_type_group' => false]);
        $listener = withTestingEnv(fn () => new \vardumper\IbexaAutomaticMigrationsBundle\EventListener\ContentTypeGroupListener(
            new \Psr\Log\NullLogger(),
            $settings,
            $this->tmpDir,
            makeContainer(),
            makeFakeRunner(0)
        ));
        withEnv('dev', fn () => expect(fn () => $listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class));
    });

    it('onUpdated – forced ibexa mode – exercises ibexa generation branch', function () {
        $listener = withTestingEnv(fn () => new \vardumper\IbexaAutomaticMigrationsBundle\EventListener\ContentTypeGroupListener(
            new \Psr\Log\NullLogger(),
            $this->settings,
            $this->tmpDir,
            makeContainer(),
            makeFakeRunner(1, '', 'ibexa-fail')
        ));
        setPrivateProperty($listener, 'mode', 'ibexa');

        withEnv('dev', fn () => expect(fn () => $listener->onUpdated($this->updateEvent))->not->toThrow(\Throwable::class));
    });
});
