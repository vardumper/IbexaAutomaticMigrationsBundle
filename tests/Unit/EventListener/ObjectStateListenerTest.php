<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\Events\ObjectState\CreateObjectStateEvent;
use Ibexa\Contracts\Core\Repository\Events\ObjectState\DeleteObjectStateEvent;
use Ibexa\Contracts\Core\Repository\Events\ObjectState\UpdateObjectStateEvent;
use Ibexa\Contracts\Core\Repository\Values\ObjectState\ObjectState;
use Ibexa\Contracts\Core\Repository\Values\ObjectState\ObjectStateCreateStruct;
use Ibexa\Contracts\Core\Repository\Values\ObjectState\ObjectStateGroup;
use Ibexa\Contracts\Core\Repository\Values\ObjectState\ObjectStateUpdateStruct;
use Psr\Log\NullLogger;
use vardumper\IbexaAutomaticMigrationsBundle\EventListener\ObjectStateListener;

describe('ObjectStateListener', function () {
    beforeEach(function () {
        $this->tmpDir = makeTmpDir();
        $this->listener = new ObjectStateListener(
            new NullLogger(),
            makeSettingsService($this->tmpDir, true, ['object_state' => true]),
            $this->tmpDir,
            makeContainer()
        );
        $state = $this->createStub(ObjectState::class);
        $group = $this->createStub(ObjectStateGroup::class);
        $createStruct = new ObjectStateCreateStruct(['identifier' => 'test_state']);
        $updateStruct = new ObjectStateUpdateStruct(['identifier' => 'test_state']);

        $this->createEvent = new CreateObjectStateEvent($state, $group, $createStruct);
        $this->updateEvent = new UpdateObjectStateEvent($state, $state, $updateStruct);
        $this->deleteEvent = new DeleteObjectStateEvent($state);
    });

    afterEach(function () {
        removeTmpDir($this->tmpDir);
    });

    it('can be instantiated and creates destination directory', function () {
        expect($this->listener)->toBeInstanceOf(ObjectStateListener::class);
        expect(is_dir($this->tmpDir . '/src/MigrationsDefinitions'))->toBeTrue();
    });

    it('implements EventSubscriberInterface', function () {
        expect($this->listener)->toBeInstanceOf(\Symfony\Component\EventDispatcher\EventSubscriberInterface::class);
    });

    it('getSubscribedEvents registers for CreateObjectStateEvent', function () {
        $events = ObjectStateListener::getSubscribedEvents();
        expect($events)->toHaveKey(CreateObjectStateEvent::class);
        expect($events[CreateObjectStateEvent::class])->toBe('onCreated');
    });

    it('getSubscribedEvents registers for UpdateObjectStateEvent', function () {
        $events = ObjectStateListener::getSubscribedEvents();
        expect($events)->toHaveKey(UpdateObjectStateEvent::class);
        expect($events[UpdateObjectStateEvent::class])->toBe('onUpdated');
    });

    it('getSubscribedEvents registers for DeleteObjectStateEvent', function () {
        $events = ObjectStateListener::getSubscribedEvents();
        expect($events)->toHaveKey(DeleteObjectStateEvent::class);
        expect($events[DeleteObjectStateEvent::class])->toBe('onDeleted');
    });

    it('onCreated returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onCreated($this->createEvent))->not->toThrow(\Throwable::class);
    });

    it('onUpdated returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onUpdated($this->updateEvent))->not->toThrow(\Throwable::class);
    });

    it('onDeleted returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onDeleted($this->deleteEvent))->not->toThrow(\Throwable::class);
    });

    it('onCreated returns early when type disabled in dev env', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';
        try {
            $listener = new ObjectStateListener(
                new NullLogger(),
                makeSettingsService($this->tmpDir, true, ['object_state' => false]),
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
