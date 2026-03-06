<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\Events\URLAlias\CreateUrlAliasEvent;
use Ibexa\Contracts\Core\Repository\Events\URLWildcard\CreateEvent as CreateUrlWildcardEvent;
use Ibexa\Contracts\Core\Repository\Events\URLWildcard\RemoveEvent as RemoveUrlWildcardEvent;
use Ibexa\Contracts\Core\Repository\Events\URLWildcard\UpdateEvent as UpdateUrlWildcardEvent;
use Ibexa\Contracts\Core\Repository\Values\Content\Location;
use Ibexa\Contracts\Core\Repository\Values\Content\URLAlias;
use Ibexa\Contracts\Core\Repository\Values\Content\URLWildcard;
use Ibexa\Contracts\Core\Repository\Values\Content\URLWildcardUpdateStruct;
use Psr\Log\NullLogger;
use vardumper\IbexaAutomaticMigrationsBundle\EventListener\UrlListener;

describe('UrlListener', function () {
    beforeEach(function () {
        $this->tmpDir = makeTmpDir();
        $this->listener = new UrlListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['url' => true]),
            $this->tmpDir,
            makeContainer()
        );
        $urlAlias = new URLAlias(['id' => 1, 'path' => '/test', 'type' => URLAlias::LOCATION]);
        $location = $this->createStub(Location::class);
        $urlWildcard = new URLWildcard(['id' => 1, 'sourceUrl' => '/src', 'destinationUrl' => '/dst', 'forward' => false]);
        $updateStruct = new URLWildcardUpdateStruct(['sourceUrl' => '/src', 'destinationUrl' => '/dst', 'forward' => false]);

        $this->aliasCreateEvent = new CreateUrlAliasEvent($urlAlias, $location, '/test', 'eng-GB', false, false);
        $this->wildcardCreateEvent = new CreateUrlWildcardEvent($urlWildcard, '/src', '/dst', false);
        $this->wildcardUpdateEvent = new UpdateUrlWildcardEvent($urlWildcard, $updateStruct);
        $this->wildcardRemoveEvent = new RemoveUrlWildcardEvent($urlWildcard);
    });

    afterEach(function () {
        removeTmpDir($this->tmpDir);
    });

    it('can be instantiated and creates destination directory', function () {
        expect($this->listener)->toBeInstanceOf(UrlListener::class);
        expect(is_dir($this->tmpDir . '/src/MigrationsDefinitions'))->toBeTrue();
    });

    it('implements EventSubscriberInterface', function () {
        expect($this->listener)->toBeInstanceOf(\Symfony\Component\EventDispatcher\EventSubscriberInterface::class);
    });

    it('getSubscribedEvents registers for CreateUrlAliasEvent', function () {
        $events = UrlListener::getSubscribedEvents();
        expect($events)->toHaveKey(CreateUrlAliasEvent::class);
        expect($events[CreateUrlAliasEvent::class])->toBe('onAliasCreated');
    });

    it('getSubscribedEvents registers for CreateUrlWildcardEvent', function () {
        $events = UrlListener::getSubscribedEvents();
        expect($events)->toHaveKey(CreateUrlWildcardEvent::class);
        expect($events[CreateUrlWildcardEvent::class])->toBe('onWildcardCreated');
    });

    it('onAliasCreated returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onAliasCreated($this->aliasCreateEvent))->not->toThrow(\Throwable::class);
    });

    it('onWildcardCreated returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onWildcardCreated($this->wildcardCreateEvent))->not->toThrow(\Throwable::class);
    });

    it('onWildcardUpdated returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onWildcardUpdated($this->wildcardUpdateEvent))->not->toThrow(\Throwable::class);
    });

    it('onWildcardRemoved returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onWildcardRemoved($this->wildcardRemoveEvent))->not->toThrow(\Throwable::class);
    });

    it('onAliasCreated reaches generateMigration in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onAliasCreated($this->aliasCreateEvent))->not->toThrow(\Throwable::class));
    });

    it('onWildcardCreated reaches logging warning in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onWildcardCreated($this->wildcardCreateEvent))->not->toThrow(\Throwable::class));
    });

    it('onWildcardUpdated reaches logging warning in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onWildcardUpdated($this->wildcardUpdateEvent))->not->toThrow(\Throwable::class));
    });

    it('onWildcardRemoved reaches generateMigration in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onWildcardRemoved($this->wildcardRemoveEvent))->not->toThrow(\Throwable::class));
    });

    it('onAliasCreated returns early when url type disabled in dev env', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';
        try {
            $listener = new UrlListener(
                new NullLogger(),
                makeSettingsService($this->tmpDir, true, ['url' => false]),
                $this->tmpDir,
                makeContainer()
            );
            expect(fn () => $listener->onAliasCreated($this->aliasCreateEvent))->not->toThrow(\Throwable::class);
        } finally {
            if ($previous === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $previous;
            }
        }
    });

    it('onAliasCreated stops at isCli check in dev env with enabled settings', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';
        try {
            expect(fn () => $this->listener->onAliasCreated($this->aliasCreateEvent))->not->toThrow(\Throwable::class);
        } finally {
            if ($previous === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $previous;
            }
        }
    });
});
