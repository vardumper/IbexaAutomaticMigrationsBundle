<?php

declare(strict_types=1);

use Ibexa\AdminUi\Menu\Event\ConfigureMenuEvent;
use Ibexa\AdminUi\Menu\MenuItemFactory;
use Ibexa\Contracts\Core\Repository\PermissionResolver;
use Knp\Menu\ItemInterface;
use vardumper\IbexaAutomaticMigrationsBundle\EventSubscriber\MigrationsMenuSubscriber;

describe('MigrationsMenuSubscriber', function () {
    it('can be instantiated', function () {
        $menuFactory = $this->createStub(MenuItemFactory::class);
        $permissionResolver = $this->createStub(PermissionResolver::class);

        $subscriber = new MigrationsMenuSubscriber($menuFactory, $permissionResolver);

        expect($subscriber)->toBeInstanceOf(MigrationsMenuSubscriber::class);
    });

    it('implements EventSubscriberInterface', function () {
        $menuFactory = $this->createStub(MenuItemFactory::class);
        $permissionResolver = $this->createStub(PermissionResolver::class);

        $subscriber = new MigrationsMenuSubscriber($menuFactory, $permissionResolver);

        expect($subscriber)->toBeInstanceOf(\Symfony\Component\EventDispatcher\EventSubscriberInterface::class);
    });

    it('getSubscribedEvents registers for MAIN_MENU event', function () {
        $events = MigrationsMenuSubscriber::getSubscribedEvents();

        expect($events)->toHaveKey(ConfigureMenuEvent::MAIN_MENU);
    });

    it('getSubscribedEvents maps MAIN_MENU to onMainMenuConfigure', function () {
        $events = MigrationsMenuSubscriber::getSubscribedEvents();

        $handler = $events[ConfigureMenuEvent::MAIN_MENU];
        $method = is_array($handler) ? $handler[0] : $handler;

        expect($method)->toBe('onMainMenuConfigure');
    });

    it('onMainMenuConfigure does nothing when permission is denied', function () {
        $permissionResolver = $this->createStub(PermissionResolver::class);
        $permissionResolver->method('hasAccess')->willReturn(false);

        $menuFactory = $this->createStub(MenuItemFactory::class);
        $subscriber = new MigrationsMenuSubscriber($menuFactory, $permissionResolver);

        $menu = $this->createMock(ItemInterface::class);
        $menu->expects($this->never())->method('getChild');

        $factory = $this->createStub(\Knp\Menu\FactoryInterface::class);
        $event = new ConfigureMenuEvent($factory, $menu, []);

        $subscriber->onMainMenuConfigure($event);
    });

    it('onMainMenuConfigure adds menu item when permission is granted', function () {
        $permissionResolver = $this->createStub(PermissionResolver::class);
        $permissionResolver->method('hasAccess')->willReturn(true);

        $addChildCallCount = 0;
        $childMenu = $this->createMock(ItemInterface::class);
        $childMenu->method('addChild')->willReturnCallback(function () use (&$addChildCallCount, &$childMenu) {
            $addChildCallCount++;
            return $childMenu;
        });

        $menuFactory = $this->createStub(MenuItemFactory::class);
        $menuFactory->method('createItem')->willReturn($this->createStub(ItemInterface::class));

        $subscriber = new MigrationsMenuSubscriber($menuFactory, $permissionResolver);

        $menu = $this->createStub(ItemInterface::class);
        $menu->method('getChild')->willReturn($childMenu);

        $factory = $this->createStub(\Knp\Menu\FactoryInterface::class);
        $event = new ConfigureMenuEvent($factory, $menu, []);

        $subscriber->onMainMenuConfigure($event);

        expect($addChildCallCount)->toBe(1);
    });
});
