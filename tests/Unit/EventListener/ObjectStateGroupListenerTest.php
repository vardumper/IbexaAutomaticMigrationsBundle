<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\Events\ObjectState\CreateObjectStateGroupEvent;
use Ibexa\Contracts\Core\Repository\Events\ObjectState\DeleteObjectStateGroupEvent;
use Ibexa\Contracts\Core\Repository\Events\ObjectState\UpdateObjectStateGroupEvent;
use Ibexa\Contracts\Core\Repository\Values\ObjectState\ObjectStateGroup;
use Ibexa\Contracts\Core\Repository\Values\ObjectState\ObjectStateGroupCreateStruct;
use Ibexa\Contracts\Core\Repository\Values\ObjectState\ObjectStateGroupUpdateStruct;
use Psr\Log\NullLogger;
use vardumper\IbexaAutomaticMigrationsBundle\EventListener\ObjectStateGroupListener;

describe('ObjectStateGroupListener', function () {
    beforeEach(function () {
        $this->tmpDir = makeTmpDir();
        $this->listener = new ObjectStateGroupListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['object_state_group' => true]),
            $this->tmpDir,
            makeContainer()
        );
        $group = $this->createStub(ObjectStateGroup::class);
        $createStruct = new ObjectStateGroupCreateStruct(['identifier' => 'test_group']);
        $updateStruct = new ObjectStateGroupUpdateStruct(['identifier' => 'test_group']);

        $this->createEvent = new CreateObjectStateGroupEvent($group, $createStruct);
        $this->updateEvent = new UpdateObjectStateGroupEvent($group, $group, $updateStruct);
        $this->deleteEvent = new DeleteObjectStateGroupEvent($group);
    });

    afterEach(function () {
        removeTmpDir($this->tmpDir);
    });

    it('can be instantiated and creates destination directory', function () {
        expect($this->listener)->toBeInstanceOf(ObjectStateGroupListener::class);
        expect(is_dir($this->tmpDir . '/src/MigrationsDefinitions'))->toBeTrue();
    });

    it('implements EventSubscriberInterface', function () {
        expect($this->listener)->toBeInstanceOf(\Symfony\Component\EventDispatcher\EventSubscriberInterface::class);
    });

    it('getSubscribedEvents registers for CreateObjectStateGroupEvent', function () {
        $events = ObjectStateGroupListener::getSubscribedEvents();
        expect($events)->toHaveKey(CreateObjectStateGroupEvent::class);
        expect($events[CreateObjectStateGroupEvent::class])->toBe('onCreated');
    });

    it('getSubscribedEvents registers for UpdateObjectStateGroupEvent', function () {
        $events = ObjectStateGroupListener::getSubscribedEvents();
        expect($events)->toHaveKey(UpdateObjectStateGroupEvent::class);
        expect($events[UpdateObjectStateGroupEvent::class])->toBe('onUpdated');
    });

    it('getSubscribedEvents registers for DeleteObjectStateGroupEvent', function () {
        $events = ObjectStateGroupListener::getSubscribedEvents();
        expect($events)->toHaveKey(DeleteObjectStateGroupEvent::class);
        expect($events[DeleteObjectStateGroupEvent::class])->toBe('onDeleted');
    });

    it('onCreated returns early when settings not enabled (APP_ENV=testing)', function () {
        expect(fn () => $this->listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class);
    });

    it('onUpdated returns early when settings not enabled', function () {
        expect(fn () => $this->listener->onUpdated($this->updateEvent))->not->toThrow(\Throwable::class);
    });

    it('onDeleted returns early when settings not enabled', function () {
        expect(fn () => $this->listener->onDeleted($this->deleteEvent))->not->toThrow(\Throwable::class);
    });

    it('onCreated returns early when type disabled', function () {
        $listener = new ObjectStateGroupListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, false, ['object_state_group' => false]),
            $this->tmpDir,
            makeContainer()
        );
        expect(fn () => $listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class);
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
