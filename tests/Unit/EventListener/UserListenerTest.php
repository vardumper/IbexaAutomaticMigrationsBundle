<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\Events\User\BeforeDeleteUserEvent;
use Ibexa\Contracts\Core\Repository\Events\User\CreateUserEvent;
use Ibexa\Contracts\Core\Repository\Events\User\UpdateUserEvent;
use Ibexa\Contracts\Core\Repository\Values\User\User;
use Ibexa\Contracts\Core\Repository\Values\User\UserCreateStruct;
use Ibexa\Contracts\Core\Repository\Values\User\UserUpdateStruct;
use Psr\Log\NullLogger;
use vardumper\IbexaAutomaticMigrationsBundle\EventListener\UserListener;

describe('UserListener', function () {
    beforeEach(function () {
        $this->tmpDir = makeTmpDir();
        $this->listener = new UserListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['user' => true]),
            $this->tmpDir,
            makeContainer()
        );
        $user = $this->createStub(User::class);
        $createStruct = $this->createStub(UserCreateStruct::class);
        $updateStruct = new UserUpdateStruct();

        $this->createEvent = new CreateUserEvent($user, $createStruct, []);
        $this->updateEvent = new UpdateUserEvent($user, $user, $updateStruct);
        $this->deleteEvent = new BeforeDeleteUserEvent($user);
    });

    afterEach(function () {
        removeTmpDir($this->tmpDir);
    });

    it('can be instantiated and creates destination directory', function () {
        expect($this->listener)->toBeInstanceOf(UserListener::class);
        expect(is_dir($this->tmpDir . '/src/MigrationsDefinitions'))->toBeTrue();
    });

    it('implements EventSubscriberInterface', function () {
        expect($this->listener)->toBeInstanceOf(\Symfony\Component\EventDispatcher\EventSubscriberInterface::class);
    });

    it('getSubscribedEvents registers for CreateUserEvent', function () {
        $events = UserListener::getSubscribedEvents();
        expect($events)->toHaveKey(CreateUserEvent::class);
        expect($events[CreateUserEvent::class])->toBe('onCreated');
    });

    it('getSubscribedEvents registers for UpdateUserEvent', function () {
        $events = UserListener::getSubscribedEvents();
        expect($events)->toHaveKey(UpdateUserEvent::class);
        expect($events[UpdateUserEvent::class])->toBe('onUpdated');
    });

    it('getSubscribedEvents registers for BeforeDeleteUserEvent', function () {
        $events = UserListener::getSubscribedEvents();
        expect($events)->toHaveKey(BeforeDeleteUserEvent::class);
        expect($events[BeforeDeleteUserEvent::class])->toBe('onBeforeDeleted');
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

    it('onCreated returns early when user type disabled in dev env', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';
        try {
            $listener = new UserListener(
                new NullLogger(),
                makeSettingsService($this->tmpDir, true, ['user' => false]),
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
});
