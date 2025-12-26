<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\EventListener;

use Ibexa\Contracts\Core\Repository\Events\Section\CreateSectionEvent;
use Ibexa\Contracts\Core\Repository\Events\Section\DeleteSectionEvent;
use Ibexa\Contracts\Core\Repository\Events\Section\UpdateSectionEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Process\Process;
use vardumper\IbexaAutomaticMigrationsBundle\Helper\Helper;
use vardumper\IbexaAutomaticMigrationsBundle\Service\SettingsService;

final class SectionListener
{
    private bool $isCli = false;
    private ?string $mode = null;
    private string $projectDir;
    private ?string $destination;
    /** @phpstan-ignore property.onlyWritten */
    private ContainerInterface $container;
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

    #[AsEventListener(CreateSectionEvent::class)]
    public function onCreated(CreateSectionEvent $event): void
    {
        if (!$this->settingsService->isEnabled() || !$this->settingsService->isTypeEnabled('section')) {
            return;
        }

        if ($this->isCli) {
            return;
        }

        $this->generateMigration($event->getSection(), 'create');
    }

    #[AsEventListener(UpdateSectionEvent::class)]
    public function onUpdated(UpdateSectionEvent $event): void
    {
        if (!$this->settingsService->isEnabled() || !$this->settingsService->isTypeEnabled('section')) {
            return;
        }

        if ($this->isCli) {
            return;
        }

        $this->generateMigration($event->getSection(), 'update');
    }

    #[AsEventListener(DeleteSectionEvent::class)]
    public function onDeleted(DeleteSectionEvent $event): void
    {
        if (!$this->settingsService->isEnabled() || !$this->settingsService->isTypeEnabled('section')) {
            return;
        }

        if ($this->isCli) {
            return;
        }

        $this->generateMigration($event->getSection(), 'delete');
    }

    private function generateMigration(\Ibexa\Contracts\Core\Repository\Values\Content\Section $section, string $mode): void
    {
        $this->logger->info('Starting section migration generation', ['mode' => $mode, 'identifier' => $section->identifier, 'id' => $section->id]);

        if ($this->mode !== 'kaliop' && $this->mode !== 'ibexa') {
            $this->logger->info('Skipping migration generation - not using kaliop or ibexa mode', ['current_mode' => $this->mode]);
            return;
        }

        try {
            $matchValue = $section->identifier;
            $name = 'auto_section_' . $mode . '_' . (string) $matchValue;

            if ($this->mode === 'kaliop') {
                $inputArray = [
                    '--format' => 'yml',
                    '--type' => 'section',
                    '--mode' => $mode,
                    '--match-type' => 'section_identifier',
                    '--match-value' => (string) $matchValue,
                    'bundle' => $this->destination,
                    'name' => $name,
                ];
                $command = 'kaliop:migration:generate';
            } elseif ($this->mode === 'ibexa') {
                $now = new \DateTime();
                $inputArray = [
                    '--format' => 'yaml',
                    '--type' => 'section',
                    '--mode' => $mode,
                    '--match-property' => 'section_identifier',
                    '--value' => (string) $matchValue,
                    '--file' => $now->format('Y_m_d_H_i_s_') . $name . '.yaml',
                ];
                $command = 'ibexa:migrations:generate';
            }

            $flags = [];
            foreach ($inputArray as $k => $v) {
                if (str_starts_with((string) $k, '--') || str_starts_with((string) $k, '-')) {
                    $flags[] = $k . '=' . $v;
                }
            }
            $cmd = array_merge($this->consoleCommand, [
                $command,
            ], $flags);
            if ($this->mode === 'kaliop') {
                $cmd = array_merge($cmd, [$this->destination, $name]);
            }
            $process = new Process($cmd, $this->projectDir);

            $process->run();
            $code = $process->getExitCode();
            $this->logger->info('Section migration generate process finished (' . $mode . ')', ['name' => $name, 'code' => $code, 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()]);
            if ($code == 0) {
                // Mark as executed if needed, similar to others
            } else {
                $this->logger->error('Section migration generation failed', ['code' => $code, 'error' => $process->getErrorOutput()]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate section migration programmatically', ['mode' => $mode, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }
}
