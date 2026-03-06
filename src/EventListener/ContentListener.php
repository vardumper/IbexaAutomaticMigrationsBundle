<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\EventListener;

use Ibexa\Contracts\Core\Repository\Events\Content\BeforeDeleteContentEvent;
use Ibexa\Contracts\Core\Repository\Events\Content\CreateContentEvent;
use Ibexa\Contracts\Core\Repository\Events\Content\UpdateContentEvent;
use Ibexa\Contracts\Core\Repository\Values\Content\ContentInfo;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Process\Process;
use vardumper\IbexaAutomaticMigrationsBundle\Helper\Helper;

final class ContentListener
{
    private bool $isCli = false;
    private ?string $mode = null;
    private string $projectDir;
    private string $destination;
    private array $consoleCommand;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly \vardumper\IbexaAutomaticMigrationsBundle\Service\SettingsService $settingsService,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
    ) {
        $this->projectDir = rtrim($projectDir, DIRECTORY_SEPARATOR);
        $this->isCli = PHP_SAPI === 'cli';
        $this->consoleCommand = ['php', '-d', 'memory_limit=512M', $this->projectDir . '/bin/console'];
        $this->mode = Helper::determineMode();
        $this->destination = Helper::determineDestination($this->projectDir);
        if (!is_dir($this->destination)) {
            mkdir($this->destination, 0777, true);
        }
    }

    #[AsEventListener(CreateContentEvent::class)]
    public function onCreated(CreateContentEvent $event): void
    {
        if (!$this->settingsService->isEnabled()) {
            return;
        }

        $content = $event->getContent();

        if (!$this->settingsService->isTypeEnabled('content')) {
            return;
        }
        if ($this->isCli) {
            $this->logger->info('Skipping content migration generation in CLI');
            return;
        }
        $this->generateMigration($content->contentInfo, 'create');
    }

    #[AsEventListener(UpdateContentEvent::class)]
    public function onUpdated(UpdateContentEvent $event): void
    {
        if (!$this->settingsService->isEnabled()) {
            return;
        }

        $content = $event->getContent();

        if (!$this->settingsService->isTypeEnabled('content')) {
            return;
        }
        if ($this->isCli) {
            $this->logger->info('Skipping content migration generation in CLI');
            return;
        }
        $this->generateMigration($content->contentInfo, 'update');
    }

    #[AsEventListener(BeforeDeleteContentEvent::class)]
    public function onBeforeDeleted(BeforeDeleteContentEvent $event): void
    {
        if (!$this->settingsService->isEnabled()) {
            return;
        }

        $contentInfo = $event->getContentInfo();

        if (!$this->settingsService->isTypeEnabled('content')) {
            return;
        }
        if ($this->isCli) {
            $this->logger->info('Skipping content migration generation in CLI');
            return;
        }
        // For delete we generate BEFORE deletion
        $this->generateMigration($contentInfo, 'delete');
    }

    private function generateMigration(ContentInfo $contentInfo, string $mode): void
    {
        try {
            $contentId = $contentInfo->id;
            $locationId = $contentInfo->mainLocationId ?? null;
            $matchValue = $contentId !== 0 ? $contentId : $locationId;
            $name = 'auto_content_' . $mode . '_' . (string)$matchValue;

            if ($this->mode === 'kaliop') {
                $inputArray = [
                    '--format' => 'yml',
                    '--type' => 'content',
                    '--mode' => $mode,
                    '--match-type' => $contentId !== 0 ? 'content_id' : 'location_id',
                    '--match-value' => (string)$matchValue,
                    'bundle' => $this->destination,
                    'name' => $name,
                ];
                $command = 'kaliop:migration:generate';
            } elseif ($this->mode === 'ibexa') {
                $now = new \DateTime();
                $inputArray = [
                    '--format' => 'yaml',
                    '--type' => 'content',
                    '--mode' => $mode,
                    '--match-property' => $contentId !== 0 ? 'content_id' : 'location_id',
                    '--value' => (string)$matchValue,
                    '--file' => $now->format('Y_m_d_H_i_s_') . $name . '.yaml',
                ];
                $command = 'ibexa:migrations:generate';
            } else {
                $this->logger->info('Skipping migration generation - unknown mode');
                return;
            }

            $flags = [];
            foreach ($inputArray as $k => $v) {
                if (str_starts_with((string)$k, '--') || str_starts_with((string)$k, '-')) {
                    $flags[] = $k . '=' . $v;
                }
            }
            $cmd = array_merge($this->consoleCommand, [$command], $flags);
            if ($this->mode === 'kaliop') {
                $cmd = array_merge($cmd, [$this->destination, $name]);
            }
            $process = new Process($cmd, $this->projectDir);
            $process->run();
            $code = $process->getExitCode();
            $this->logger->info('Content migration generate process finished', ['name' => $name, 'code' => $code, 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate content migration programmatically', ['exception' => $e->getMessage()]);
        }
    }
}
