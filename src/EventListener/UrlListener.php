<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\EventListener;

use Ibexa\Contracts\Core\Repository\Events\URLAlias\CreateUrlAliasEvent;
use Ibexa\Contracts\Core\Repository\Events\URLWildcard\CreateEvent as CreateUrlWildcardEvent;
use Ibexa\Contracts\Core\Repository\Events\URLWildcard\RemoveEvent as RemoveUrlWildcardEvent;
use Ibexa\Contracts\Core\Repository\Events\URLWildcard\UpdateEvent as UpdateUrlWildcardEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Process\Process;
use vardumper\IbexaAutomaticMigrationsBundle\Helper\Helper;
use vardumper\IbexaAutomaticMigrationsBundle\Service\SettingsService;

final class UrlListener implements EventSubscriberInterface
{
    private bool $isCli = false;
    private ?string $mode = null;
    private string $projectDir;
    private string $destination;
    private ContainerInterface $container;
    private array $consoleCommand;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settingsService,
        string $projectDir,
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

    public static function getSubscribedEvents(): array
    {
        return [
            CreateUrlAliasEvent::class => 'onAliasCreated',
            CreateUrlWildcardEvent::class => 'onWildcardCreated',
            UpdateUrlWildcardEvent::class => 'onWildcardUpdated',
            RemoveUrlWildcardEvent::class => 'onWildcardRemoved',
            // Note: URL aliases are typically not updated or deleted through admin interface in the same way
            // Add other events if needed
        ];
    }

    public function onAliasCreated(CreateUrlAliasEvent $event): void
    {
        if (!$this->settingsService->isEnabled() || !$this->settingsService->isTypeEnabled('url')) {
            return;
        }

        $this->logger->info('IbexaAutomaticMigrationsBundle: CreateUrlAliasEvent received', ['event' => get_class($event)]);

        // Skip in CLI to prevent creating redundant migrations when executing migrations that create/update URL aliases
        if ($this->isCli) {
            $this->logger->info('IbexaAutomaticMigrationsBundle: Skipping in CLI to avoid redundant migrations during execution');
            return;
        }

        $urlAlias = $event->getUrlAlias();
        $this->logger->info('CreateUrlAliasEvent received', ['id' => $urlAlias->id, 'path' => $urlAlias->path, 'languageCodes' => $urlAlias->languageCodes]);

        $this->generateMigration($urlAlias, 'create', 'url_alias');
    }

    public function onWildcardCreated(CreateUrlWildcardEvent $event): void
    {
        if (!$this->settingsService->isEnabled() || !$this->settingsService->isTypeEnabled('url')) {
            return;
        }

        $this->logger->info('IbexaAutomaticMigrationsBundle: CreateUrlWildcardEvent received', ['event' => get_class($event)]);

        // Skip in CLI to prevent creating redundant migrations when executing migrations that create/update URL wildcards
        if ($this->isCli) {
            $this->logger->info('IbexaAutomaticMigrationsBundle: Skipping in CLI to avoid redundant migrations during execution');
            return;
        }

        $urlWildcard = $event->getUrlWildcard();
        $this->logger->info('CreateUrlWildcardEvent received', ['id' => $urlWildcard->id, 'source' => $urlWildcard->sourceUrl, 'destination' => $urlWildcard->destinationUrl]);

        // Note: URL wildcards are not supported for automatic migration generation
        // because the underlying migration bundle doesn't support generating them
        $this->logger->warning('IbexaAutomaticMigrationsBundle: URL wildcards are not supported for automatic migration generation');
        return;
    }

    public function onWildcardUpdated(UpdateUrlWildcardEvent $event): void
    {
        if (!$this->settingsService->isEnabled() || !$this->settingsService->isTypeEnabled('url')) {
            return;
        }

        $this->logger->info('IbexaAutomaticMigrationsBundle: UpdateUrlWildcardEvent received', ['event' => get_class($event)]);

        // Skip in CLI to prevent creating redundant migrations when executing migrations that create/update URL wildcards
        if ($this->isCli) {
            $this->logger->info('IbexaAutomaticMigrationsBundle: Skipping in CLI to avoid redundant migrations during execution');
            return;
        }

        $urlWildcard = $event->getUrlWildcard();
        $this->logger->info('UpdateUrlWildcardEvent received', ['id' => $urlWildcard->id, 'source' => $urlWildcard->sourceUrl, 'destination' => $urlWildcard->destinationUrl]);

        // Note: URL wildcards are not supported for automatic migration generation
        // because the underlying migration bundle doesn't support generating them
        $this->logger->warning('IbexaAutomaticMigrationsBundle: URL wildcards are not supported for automatic migration generation');
        return;
    }

    public function onWildcardRemoved(RemoveUrlWildcardEvent $event): void
    {
        if (!$this->settingsService->isEnabled() || !$this->settingsService->isTypeEnabled('url')) {
            return;
        }

        $this->logger->info('IbexaAutomaticMigrationsBundle: RemoveUrlWildcardEvent received', ['event' => get_class($event)]);

        // Skip in CLI to prevent creating redundant migrations when executing migrations that create/update URL wildcards
        if ($this->isCli) {
            $this->logger->info('IbexaAutomaticMigrationsBundle: Skipping in CLI to avoid redundant migrations during execution');
            return;
        }

        $urlWildcard = $event->getUrlWildcard();
        $this->logger->info('RemoveUrlWildcardEvent received', ['id' => $urlWildcard->id, 'source' => $urlWildcard->sourceUrl, 'destination' => $urlWildcard->destinationUrl]);

        // Note: URL wildcards are not supported for automatic migration generation
        // because the underlying migration bundle doesn't support generating them
        $this->logger->warning('IbexaAutomaticMigrationsBundle: URL wildcards are not supported for automatic migration generation');
        return;
    }

    private function generateMigration(\Ibexa\Contracts\Core\Repository\Values\Content\URLAlias|\Ibexa\Contracts\Core\Repository\Values\Content\URLWildcard $entity, string $mode, string $type): void
    {
        if ($this->mode !== 'kaliop' && $this->mode !== 'ibexa') {
            $this->logger->info('Skipping migration generation - not using kaliop or ibexa mode', ['current_mode' => $this->mode]);
            return;
        }

        try {
            if ($entity instanceof \Ibexa\Contracts\Core\Repository\Values\Content\URLAlias) {
                $matchValue = $entity->path;
                $matchType = 'path';
            } elseif ($entity instanceof \Ibexa\Contracts\Core\Repository\Values\Content\URLWildcard) {
                $matchValue = (string) $entity->id;
                $matchType = 'url_id';
            }

            $name = 'auto_' . str_replace('_', '', $type) . '_' . $mode . '_' . md5($matchValue . $type);

            if ($this->mode === 'kaliop') {
                $inputArray = [
                    '--format' => 'yml',
                    '--type' => $type,
                    '--mode' => $mode,
                    '--match-type' => $matchType,
                    '--match-value' => $matchValue,
                    'bundle' => $this->destination,
                    'name' => $name,
                ];
                $command = 'kaliop:migration:generate';
            } elseif ($this->mode === 'ibexa') {
                $now = new \DateTime();
                $inputArray = [
                    '--format' => 'yaml',
                    '--type' => $type,
                    '--mode' => $mode,
                    '--match-property' => $matchType,
                    '--value' => $matchValue,
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
            $this->logger->info('URL migration generate process finished (' . $mode . ')', ['name' => $name, 'type' => $type, 'code' => $code, 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()]);
            if ($code == 0) {
                if ($this->mode === 'ibexa') {
                    $fileName = $inputArray['--file'];
                    $this->logger->info('Using specified filename for ibexa', ['fileName' => $fileName]);
                } else {
                    // Find the newest migration file in the destination directory for kaliop
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
                        $this->logger->info('Newest migration file found for kaliop', ['fileName' => $fileName, 'path' => $latestFile]);
                    } else {
                        $this->logger->warning('No migration files found after generation for kaliop');
                        return;
                    }
                }

                $fullPath = $this->destination . DIRECTORY_SEPARATOR . $fileName;
                $md5 = md5_file($fullPath);
                try {
                    if ($this->mode === 'ibexa') {
                        $conn = $this->container->get('doctrine.dbal.default_connection');
                        $table = 'ibexa_migrations';
                        $data = [
                            'executed_at' => new \DateTime(),
                            'execution_time' => null
                        ];
                        $identifier = ['name' => $fileName];
                        $affected = $conn->update($table, $data, $identifier);
                        if ($affected === 0) {
                            // Row not found, insert
                            $conn->insert($table, array_merge($identifier, $data));
                        }
                    } elseif ($this->mode === 'kaliop') {
                        $conn = $this->container->get('doctrine.dbal.default_connection');
                        $table = 'kaliop_migrations';
                        $data = [
                            'execution_date' => time(),
                            'status' => 2  // STATUS_DONE
                        ];
                        $identifier = ['migration' => $fileName];
                        $affected = $conn->update($table, $data, $identifier);
                        if ($affected === 0) {
                            // Row not found, insert
                            $conn->insert($table, array_merge($identifier, $data, [
                                'md5' => md5_file($fullPath),
                                'path' => $fullPath,
                                'execution_error' => null
                            ]));
                        }
                        $this->logger->info('Migration marked as executed in DB', ['filename' => $fileName, 'table' => $table, 'action' => $affected > 0 ? 'updated' : 'inserted']);
                    }
                    $this->logger->info('Migration marked as executed', ['filename' => $fileName, 'mode' => $this->mode]);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to mark migration as executed', ['exception' => $e->getMessage()]);
                }
            } else {
                $this->logger->error('URL migration generation failed', ['code' => $code, 'error' => $process->getErrorOutput()]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate URL migration programmatically', ['mode' => $mode, 'type' => $type, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }
}
