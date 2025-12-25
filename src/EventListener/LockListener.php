<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\EventListener;

use Ibexa\Contracts\Core\Repository\Events\Content\Lock\LockCreateEvent;
use Ibexa\Contracts\Core\Repository\Events\Content\Lock\LockDeleteEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use vardumper\IbexaAutomaticMigrationsBundle\Helper\Helper;
use vardumper\IbexaAutomaticMigrationsBundle\Service\SettingsService;

final class LockListener
{
    private bool $isCli = false;
    private ?string $mode = null;
    private string $projectDir;
    private ?string $destination;
    /** @var array<string> */
    private array $consoleCommand;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settingsService,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        #[Autowire(service: 'service_container')]
        ContainerInterface $container
    ) {
        $this->container = $container;
        $this->projectDir = rtrim($projectDir, DIRECTORY_SEPARATOR);
        $this->isCli = PHP_SAPI === 'cli';
        $this->consoleCommand = ['php', '-d', 'memory_limit=512M', $this->projectDir . '/bin/console'];
        $this->mode = Helper::determineMode();
        $this->destination = Helper::determineDestination($this->projectDir);
        if (!is_dir($this->destination)) {
            mkdir($this->destination, 0777, true);
        }
    }

    #[AsEventListener(LockCreateEvent::class)]
    public function onCreated(LockCreateEvent $event): void
    {
        if (!$this->settingsService->isEnabled() || !$this->settingsService->isTypeEnabled('lock')) {
            return;
        }

        if ($this->isCli) {
            return;
        }

        // Note: Lock creation might not need migration, but for completeness
        $this->logger->info('Lock created', ['contentId' => $event->getContentInfo()->id]);
    }

    #[AsEventListener(LockDeleteEvent::class)]
    public function onDeleted(LockDeleteEvent $event): void
    {
        if (!$this->settingsService->isEnabled() || !$this->settingsService->isTypeEnabled('lock')) {
            return;
        }

        if ($this->isCli) {
            return;
        }

        $this->logger->info('Lock deleted', ['contentId' => $event->getContentInfo()->id]);
    }
}
