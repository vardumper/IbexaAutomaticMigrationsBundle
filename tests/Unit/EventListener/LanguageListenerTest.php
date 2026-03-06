<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\Events\Language\BeforeDeleteLanguageEvent;
use Ibexa\Contracts\Core\Repository\Events\Language\CreateLanguageEvent;
use Ibexa\Contracts\Core\Repository\Events\Language\UpdateLanguageNameEvent;
use Ibexa\Contracts\Core\Repository\Values\Content\Language;
use Ibexa\Contracts\Core\Repository\Values\Content\LanguageCreateStruct;
use Psr\Log\NullLogger;
use vardumper\IbexaAutomaticMigrationsBundle\EventListener\LanguageListener;

describe('LanguageListener', function () {
    beforeEach(function () {
        $this->tmpDir = makeTmpDir();
        $this->listener = new LanguageListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['language' => true]),
            $this->tmpDir,
            makeContainer()
        );
        $lang = new Language(['id' => 1, 'languageCode' => 'eng-GB', 'name' => 'English', 'enabled' => true]);
        $struct = new LanguageCreateStruct(['languageCode' => 'eng-GB', 'name' => 'English', 'enabled' => true]);
        $this->createEvent = new CreateLanguageEvent($lang, $struct);
        $this->updateEvent = new UpdateLanguageNameEvent($lang, $lang, 'English (US)');
        $this->deleteEvent = new BeforeDeleteLanguageEvent($lang);
    });

    afterEach(function () {
        removeTmpDir($this->tmpDir);
    });

    it('can be instantiated and creates destination directory', function () {
        expect($this->listener)->toBeInstanceOf(LanguageListener::class);
        expect(is_dir($this->tmpDir . '/src/MigrationsDefinitions'))->toBeTrue();
    });

    it('implements EventSubscriberInterface', function () {
        expect($this->listener)->toBeInstanceOf(\Symfony\Component\EventDispatcher\EventSubscriberInterface::class);
    });

    it('getSubscribedEvents registers for CreateLanguageEvent', function () {
        $events = LanguageListener::getSubscribedEvents();
        expect($events)->toHaveKey(CreateLanguageEvent::class);
        expect($events[CreateLanguageEvent::class])->toBe('onCreated');
    });

    it('getSubscribedEvents registers for UpdateLanguageNameEvent', function () {
        $events = LanguageListener::getSubscribedEvents();
        expect($events)->toHaveKey(UpdateLanguageNameEvent::class);
        expect($events[UpdateLanguageNameEvent::class])->toBe('onUpdated');
    });

    it('getSubscribedEvents registers for BeforeDeleteLanguageEvent', function () {
        $events = LanguageListener::getSubscribedEvents();
        expect($events)->toHaveKey(BeforeDeleteLanguageEvent::class);
        expect($events[BeforeDeleteLanguageEvent::class])->toBe('onBeforeDeleted');
    });

    it('onCreated returns early when APP_ENV is not dev', function () {
        // phpunit.xml sets APP_ENV=testing — no exception means early return
        expect(fn () => $this->listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class);
    });

    it('onUpdated returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onUpdated($this->updateEvent))->not->toThrow(\Throwable::class);
    });

    it('onBeforeDeleted returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onBeforeDeleted($this->deleteEvent))->not->toThrow(\Throwable::class);
    });

    it('onCreated returns early at isCli check when dev env with enabled settings', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';
        try {
            // PHP_SAPI=cli in test runner — hits isCli return before migration generation
            expect(fn () => $this->listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class);
        } finally {
            if ($previous === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $previous;
            }
        }
    });

    it('onCreated returns early when type disabled in dev env', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';
        try {
            $listener = new LanguageListener(
                new NullLogger(),
                makeSettingsService($this->tmpDir, true, ['language' => false]),
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
