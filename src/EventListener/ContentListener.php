<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\EventListener;

use Ibexa\Contracts\Core\Repository\Events\Content\BeforeDeleteContentEvent;
use Ibexa\Contracts\Core\Repository\Events\Content\PublishVersionEvent;
use Ibexa\Contracts\Core\Repository\Values\Content\ContentInfo;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use vardumper\IbexaAutomaticMigrationsBundle\Helper\Helper;
use vardumper\IbexaAutomaticMigrationsBundle\Process\MigrationRunnerInterface;
use vardumper\IbexaAutomaticMigrationsBundle\Process\SymfonyProcessRunner;

final class ContentListener
{
    private bool $isCli = false;
    private ?string $mode = null;
    private string $projectDir;
    private string $destination;
    private array $consoleCommand;
    private MigrationRunnerInterface $migrationRunner;
    private ContainerInterface $container;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly \vardumper\IbexaAutomaticMigrationsBundle\Service\SettingsService $settingsService,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        #[Autowire(service: 'service_container')]
        ContainerInterface $container,
        ?MigrationRunnerInterface $migrationRunner = null,
    ) {
        $this->container = $container;
        $this->migrationRunner = $migrationRunner ?? new SymfonyProcessRunner();
        $this->projectDir = rtrim($projectDir, DIRECTORY_SEPARATOR);
        $this->isCli = PHP_SAPI === 'cli' && ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null) !== 'testing';
        $this->consoleCommand = ['php', '-d', 'memory_limit=512M', $this->projectDir . '/bin/console'];
        $this->mode = Helper::determineMode();
        $this->destination = Helper::determineDestination($this->projectDir);
        if (!is_dir($this->destination)) {
            mkdir($this->destination, 0777, true);
        }
    }

    #[AsEventListener(PublishVersionEvent::class)]
    public function onPublished(PublishVersionEvent $event): void
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
        $mode = $event->getVersionInfo()->getVersionNo() === 1 ? 'create' : 'update';
        $this->generateMigration($content->contentInfo, $mode);
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
            $this->migrationRunner->run($cmd, $this->projectDir);
            $code = $this->migrationRunner->getExitCode();
            $this->logger->info('Content migration generate process finished', ['name' => $name, 'code' => $code, 'output' => $this->migrationRunner->getOutput(), 'error' => $this->migrationRunner->getErrorOutput()]);

            if ($code == 0) {
                if ($this->mode === 'ibexa') {
                    $fileName = $inputArray['--file'];
                } else {
                    $files = glob($this->destination . DIRECTORY_SEPARATOR . '*.{yml,yaml}', GLOB_BRACE);
                    $latestFile = '';
                    $latestTime = 0;
                    foreach ($files as $file) {
                        $mtime = filemtime($file);
                        if ($mtime > $latestTime) {
                            $latestTime = $mtime;
                            $latestFile = $file;
                        }
                    }
                    if ($latestFile) {
                        $fileName = basename($latestFile);
                    } else {
                        $this->logger->warning('No migration files found after generation');
                        return;
                    }
                }

                $fullPath = $this->destination . DIRECTORY_SEPARATOR . $fileName;

                try {
                    $conn = $this->container->get('doctrine.dbal.default_connection');
                    if ($this->mode === 'ibexa') {
                        $data = ['executed_at' => new \DateTime(), 'execution_time' => null];
                        $identifier = ['name' => $fileName];
                        $affected = $conn->update('ibexa_migrations', $data, $identifier);
                        if ($affected === 0) {
                            $conn->insert('ibexa_migrations', array_merge($identifier, $data));
                        }
                    } elseif ($this->mode === 'kaliop') {
                        $data = ['execution_date' => time(), 'status' => 2];
                        $identifier = ['migration' => $fileName];
                        $affected = $conn->update('kaliop_migrations', $data, $identifier);
                        if ($affected === 0) {
                            $conn->insert('kaliop_migrations', array_merge($identifier, $data, [
                                'md5' => md5_file($fullPath),
                                'path' => $fullPath,
                                'execution_error' => null,
                            ]));
                        }
                    }
                    $this->logger->info('Content migration marked as executed', ['filename' => $fileName, 'mode' => $this->mode]);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to mark content migration as executed', ['exception' => $e->getMessage()]);
                }
            } else {
                $this->logger->error('Content migration generation failed', ['code' => $code, 'error' => $this->migrationRunner->getErrorOutput()]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate content migration programmatically', ['exception' => $e->getMessage()]);
        }
    }
}
