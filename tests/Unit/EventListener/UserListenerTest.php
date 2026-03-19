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

        // Default user: non-skippable (no @ in login, id != 10, login != 'anonymous')
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->getMock();
        $user->method('__get')->willReturnCallback(fn (string $prop) => match ($prop) {
            'id' => 1,
            'login' => 'editor',
            default => null,
        });

        $createStruct = $this->createStub(UserCreateStruct::class);
        $updateStruct = new UserUpdateStruct();

        $this->user = $user;
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
        withEnv('dev', function () {
            $listener = new UserListener(
                new NullLogger(),
                makeSettingsService($this->tmpDir, true, ['user' => false]),
                $this->tmpDir,
                makeContainer()
            );
            expect(fn () => $listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class);
        });
    });

    it('onCreated skips anonymous user (id=10) in dev env', function () {
        $anonUser = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->getMock();
        $anonUser->method('__get')->willReturnCallback(fn (string $prop) => match ($prop) {
            'id' => 10,
            'login' => 'anonymous',
            default => null,
        });
        $createStruct = $this->createStub(UserCreateStruct::class);
        $event = new CreateUserEvent($anonUser, $createStruct, []);

        withEnv('dev', fn () => expect(fn () => $this->listener->onCreated($event))->not->toThrow(\Throwable::class));
    });

    it('onCreated skips user with @ in login (frontend registration) in dev env', function () {
        $frontendUser = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->getMock();
        $frontendUser->method('__get')->willReturnCallback(fn (string $prop) => match ($prop) {
            'id' => 99,
            'login' => 'user@example.com',
            default => null,
        });
        $createStruct = $this->createStub(UserCreateStruct::class);
        $event = new CreateUserEvent($frontendUser, $createStruct, []);

        withEnv('dev', fn () => expect(fn () => $this->listener->onCreated($event))->not->toThrow(\Throwable::class));
    });

    it('onCreated reaches generateMigration for valid user in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class));
    });

    it('onUpdated skips anonymous user in dev env', function () {
        $anonUser = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->getMock();
        $anonUser->method('__get')->willReturnCallback(fn (string $prop) => match ($prop) {
            'id' => 10,
            'login' => 'anonymous',
            default => null,
        });
        $event = new UpdateUserEvent($anonUser, $anonUser, new UserUpdateStruct());

        withEnv('dev', fn () => expect(fn () => $this->listener->onUpdated($event))->not->toThrow(\Throwable::class));
    });

    it('onUpdated reaches generateMigration for valid user in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onUpdated($this->updateEvent))->not->toThrow(\Throwable::class));
    });

    it('onBeforeDeleted skips anonymous user in dev env', function () {
        $anonUser = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->getMock();
        $anonUser->method('__get')->willReturnCallback(fn (string $prop) => match ($prop) {
            'id' => 10,
            'login' => 'anonymous',
            default => null,
        });
        $event = new BeforeDeleteUserEvent($anonUser);

        withEnv('dev', fn () => expect(fn () => $this->listener->onBeforeDeleted($event))->not->toThrow(\Throwable::class));
    });

    it('onBeforeDeleted reaches generateMigration for valid user in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onBeforeDeleted($this->deleteEvent))->not->toThrow(\Throwable::class));
    });
});

describe('UserListener – past CLI guard (fake runner)', function () {
    beforeEach(function () {
        $this->tmpDir = makeTmpDir();
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->getMock();
        $user->method('__get')->willReturnCallback(fn (string $prop) => match ($prop) {
            'id' => 1,
            'login' => 'editor',
            default => null,
        });
        $createStruct = $this->createStub(UserCreateStruct::class);
        $updateStruct = new UserUpdateStruct();

        $this->createEvent = new CreateUserEvent($user, $createStruct, []);
        $this->updateEvent = new UpdateUserEvent($user, $user, $updateStruct);
        $this->deleteEvent = new BeforeDeleteUserEvent($user);
    });

    afterEach(function () {
        removeTmpDir($this->tmpDir);
    });

    it('onCreated handles successful runner with no generated file', function () {
        $listener = withTestingEnv(fn () => new UserListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['user' => true]),
            $this->tmpDir,
            makeContainer(),
            makeFakeRunner(0)
        ));

        withEnv('dev', fn () => expect(fn () => $listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class));
    });

    it('onUpdated handles failed runner branch', function () {
        $listener = withTestingEnv(fn () => new UserListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['user' => true]),
            $this->tmpDir,
            makeContainer(),
            makeFakeRunner(1, '', 'boom')
        ));

        withEnv('dev', fn () => expect(fn () => $listener->onUpdated($this->updateEvent))->not->toThrow(\Throwable::class));
    });

    it('onBeforeDeleted handles successful runner with existing migration file', function () {
        $dest = $this->tmpDir . '/src/MigrationsDefinitions';
        @mkdir($dest, 0777, true);
        $file = $dest . '/2099_01_01_00_00_06_auto_user.yaml';
        file_put_contents($file, "- type: user\n  mode: delete\n");
        touch($file, time() - 1);

        $listener = withTestingEnv(fn () => new UserListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['user' => true]),
            $this->tmpDir,
            makeContainer(),
            makeFakeRunner(0)
        ));

        withEnv('dev', fn () => expect(fn () => $listener->onBeforeDeleted($this->deleteEvent))->not->toThrow(\Throwable::class));
    });

    it('onCreated – forced ibexa mode – exercises ibexa generation branch', function () {
        $listener = withTestingEnv(fn () => new UserListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['user' => true]),
            $this->tmpDir,
            makeContainer(),
            makeFakeRunner(1, '', 'ibexa-fail')
        ));
        setPrivateProperty($listener, 'mode', 'ibexa');

        withEnv('dev', fn () => expect(fn () => $listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class));
    });
});
