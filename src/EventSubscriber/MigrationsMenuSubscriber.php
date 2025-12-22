<?php declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\EventSubscriber;

use Ibexa\AdminUi\Menu\Event\ConfigureMenuEvent;
use Ibexa\AdminUi\Menu\MainMenuBuilder;
use Ibexa\AdminUi\Menu\MenuItemFactory;
use Ibexa\Contracts\Core\Repository\PermissionResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MigrationsMenuSubscriber implements EventSubscriberInterface
{
    public const ITEM_ADMIN__MIGRATIONS = 'main__admin__migrations';

    /**
     * @var \Ibexa\AdminUi\Menu\MenuItemFactory
     */
    private $menuItemFactory;

    /**
     * @var \Ibexa\Contracts\Core\Repository\PermissionResolver
     */
    private $permissionResolver;

    public function __construct(
        MenuItemFactory $menuItemFactory,
        PermissionResolver $permissionResolver
    ) {
        $this->menuItemFactory = $menuItemFactory;
        $this->permissionResolver = $permissionResolver;
    }

    public static function getSubscribedEvents()
    {
        return [
            ConfigureMenuEvent::MAIN_MENU => ['onMainMenuConfigure', 0],
        ];
    }

    public function onMainMenuConfigure(ConfigureMenuEvent $event)
    {
        $menu = $event->getMenu();

        // only for admins - temporarily removed
        // if (!$this->permissionResolver->hasAccess('setup', 'system_info')) {
        //     return;
        // }

        $menu->getChild(MainMenuBuilder::ITEM_ADMIN)->addChild(
            $this->menuItemFactory->createItem(
                self::ITEM_ADMIN__MIGRATIONS,
                [
                    'label' => 'Migrations',
                    'route' => 'migrations_list',
                    'attributes' => [
                        'class' => 'custom-menu-item',
                    ],
                    'linkAttributes' => [
                        'class' => 'custom-menu-item-link',
                    ],
                ]
            )
        );
    }
}
