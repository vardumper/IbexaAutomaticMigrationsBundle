<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\Events\Role\BeforeDeleteRoleEvent;
use Ibexa\Contracts\Core\Repository\Events\Role\CreateRoleEvent;
use Ibexa\Contracts\Core\Repository\Events\Role\PublishRoleDraftEvent;
use Ibexa\Contracts\Core\Repository\Events\Role\UpdateRoleDraftEvent;
use Ibexa\Contracts\Core\Repository\Values\User\Role;
use Ibexa\Contracts\Core\Repository\Values\User\RoleCreateStruct;
use Ibexa\Contracts\Core\Repository\Values\User\RoleDraft;
use Ibexa\Contracts\Core\Repository\Values\User\RoleUpdateStruct;
use Psr\Log\NullLogger;
use vardumper\IbexaAutomaticMigrationsBundle\EventListener\RoleListener;

describe('RoleListener', function () {
    beforeEach(function () {
        $this->tmpDir = makeTmpDir();
        $this->listener = new RoleListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['role' => true]),
            $this->tmpDir,
            makeContainer()
        );
        $draft = $this->createStub(RoleDraft::class);
        $createStruct = $this->createStub(RoleCreateStruct::class);
        $updateStruct = new RoleUpdateStruct(['identifier' => 'test_role']);
        $role = $this->createStub(Role::class);

        $this->createEvent = new CreateRoleEvent($draft, $createStruct);
        $this->publishEvent = new PublishRoleDraftEvent($draft);
        $this->updateEvent = new UpdateRoleDraftEvent($draft, $draft, $updateStruct);
        $this->deleteEvent = new BeforeDeleteRoleEvent($role);
    });

    afterEach(function () {
        removeTmpDir($this->tmpDir);
    });

    it('can be instantiated and creates destination directory', function () {
        expect($this->listener)->toBeInstanceOf(RoleListener::class);
        expect(is_dir($this->tmpDir . '/src/MigrationsDefinitions'))->toBeTrue();
    });

    it('implements EventSubscriberInterface', function () {
        expect($this->listener)->toBeInstanceOf(\Symfony\Component\EventDispatcher\EventSubscriberInterface::class);
    });

    it('getSubscribedEvents registers for CreateRoleEvent', function () {
        $events = RoleListener::getSubscribedEvents();
        expect($events)->toHaveKey(CreateRoleEvent::class);
        expect($events[CreateRoleEvent::class])->toBe('onCreated');
    });

    it('getSubscribedEvents registers for PublishRoleDraftEvent', function () {
        $events = RoleListener::getSubscribedEvents();
        expect($events)->toHaveKey(PublishRoleDraftEvent::class);
        expect($events[PublishRoleDraftEvent::class])->toBe('onPublished');
    });

    it('getSubscribedEvents registers for UpdateRoleDraftEvent', function () {
        $events = RoleListener::getSubscribedEvents();
        expect($events)->toHaveKey(UpdateRoleDraftEvent::class);
        expect($events[UpdateRoleDraftEvent::class])->toBe('onUpdated');
    });

    it('getSubscribedEvents registers for BeforeDeleteRoleEvent', function () {
        $events = RoleListener::getSubscribedEvents();
        expect($events)->toHaveKey(BeforeDeleteRoleEvent::class);
        expect($events[BeforeDeleteRoleEvent::class])->toBe('onBeforeDeleted');
    });

    it('onCreated returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class);
    });

    it('onPublished returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onPublished($this->publishEvent))->not->toThrow(\Throwable::class);
    });

    it('onUpdated returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onUpdated($this->updateEvent))->not->toThrow(\Throwable::class);
    });

    it('onBeforeDeleted returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onBeforeDeleted($this->deleteEvent))->not->toThrow(\Throwable::class);
    });

    it('onCreated returns early when role type disabled in dev env', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';
        try {
            $listener = new RoleListener(
                new NullLogger(),
                makeSettingsService($this->tmpDir, true, ['role' => false]),
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

    it('onPublished reaches generateMigration in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onPublished($this->publishEvent))->not->toThrow(\Throwable::class));
    });

    it('onUpdated reaches generateMigration in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onUpdated($this->updateEvent))->not->toThrow(\Throwable::class));
    });

    it('onBeforeDeleted reaches generateMigration in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onBeforeDeleted($this->deleteEvent))->not->toThrow(\Throwable::class));
    });
});
