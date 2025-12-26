<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\EventListener;

use Ibexa\Contracts\Core\Repository\Events\ObjectState\CreateObjectStateEvent;
use Ibexa\Contracts\Core\Repository\Events\ObjectState\DeleteObjectStateEvent;
use Ibexa\Contracts\Core\Repository\Events\ObjectState\UpdateObjectStateEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Process\Process;
use vardumper\IbexaAutomaticMigrationsBundle\Helper\Helper;
use vardumper\IbexaAutomaticMigrationsBundle\Service\SettingsService;

final class ObjectStateListener implements EventSubscriberInterface
{
    private bool $isCli = false;
    private ?string $mode = null;
    private string $projectDir;
    private string $destination;
    private ContainerInterface $container;
    private array $consoleCommand;
    /** @var array<string, int> */
    private array $recentGenerations = [];

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
            CreateObjectStateEvent::class => 'onCreated',
            UpdateObjectStateEvent::class => 'onUpdated',
            DeleteObjectStateEvent::class => 'onDeleted',
        ];
    }

    public function onCreated(CreateObjectStateEvent $event): void
    {
        $this->logger->info('IbexaAutomaticMigrationsBundle: CreateObjectStateEvent received', ['event' => get_class($event)]);

        if (!$this->settingsService->isEnabled() || !$this->settingsService->isTypeEnabled('object_state')) {
            $this->logger->info('ObjectStateListener: settings not enabled');
            return;
        }

        // Skip in CLI to prevent creating redundant migrations when executing migrations that create/update object states
        if ($this->isCli) {
            $this->logger->info('IbexaAutomaticMigrationsBundle: Skipping in CLI to avoid redundant migrations during execution');
            return;
        }

        $objectState = $event->getObjectState();
        $this->logger->info('CreateObjectStateEvent received', ['id' => $objectState->id, 'identifier' => $objectState->identifier, 'group_id' => $event->getObjectStateGroup()->id]);

        $key = $event->getObjectStateGroup()->identifier . '_' . $objectState->identifier;
        $now = time();
        if (isset($this->recentGenerations[$key]) && ($now - $this->recentGenerations[$key]) < 30) {
            $this->logger->info('Skipping duplicate object state creation event', ['key' => $key, 'time_diff' => $now - $this->recentGenerations[$key]]);
            return;
        }
        $this->recentGenerations[$key] = $now;

        $this->generateMigration($objectState, 'create');
    }

    public function onUpdated(UpdateObjectStateEvent $event): void
    {
        if (!$this->settingsService->isEnabled() || !$this->settingsService->isTypeEnabled('object_state')) {
            return;
        }

        $this->logger->info('IbexaAutomaticMigrationsBundle: UpdateObjectStateEvent received', ['event' => get_class($event)]);

        // Skip in CLI to prevent creating redundant migrations when executing migrations that create/update object states
        if ($this->isCli) {
            $this->logger->info('IbexaAutomaticMigrationsBundle: Skipping in CLI to avoid redundant migrations during execution');
            return;
        }

        $objectState = $event->getObjectState();
        $this->logger->info('UpdateObjectStateEvent received', ['id' => $objectState->id, 'identifier' => $objectState->identifier]);

        $this->generateMigration($objectState, 'update');
    }

    public function onDeleted(DeleteObjectStateEvent $event): void
    {
        if (!$this->settingsService->isEnabled() || !$this->settingsService->isTypeEnabled('object_state')) {
            return;
        }

        $this->logger->info('IbexaAutomaticMigrationsBundle: DeleteObjectStateEvent received', ['event' => get_class($event)]);

        // Skip in CLI to prevent creating redundant migrations when executing migrations that delete object states
        if ($this->isCli) {
            $this->logger->info('IbexaAutomaticMigrationsBundle: Skipping in CLI to avoid redundant migrations during execution');
            return;
        }

        $objectState = $event->getObjectState();
        $this->logger->info('DeleteObjectStateEvent received', ['id' => $objectState->id, 'identifier' => $objectState->identifier]);

        // Generate delete migration BEFORE the object state is deleted
        $this->generateMigration($objectState, 'delete');
    }

    private function generateMigration(\Ibexa\Contracts\Core\Repository\Values\ObjectState\ObjectState $objectState, string $mode): void
    {
        $this->logger->info('Starting object state migration generation', ['mode' => $mode, 'identifier' => $objectState->identifier, 'id' => $objectState->id]);

        if ($this->mode !== 'kaliop' && $this->mode !== 'ibexa') {
            $this->logger->info('Skipping migration generation - not using kaliop or ibexa mode', ['current_mode' => $this->mode]);
            return;
        }

        try {
            $matchValue = $objectState->identifier;
            $name = 'auto_objectstate_' . $mode . '_' . (string) $matchValue;

            if ($this->mode === 'kaliop') {
                $inputArray = [
                    '--format' => 'yml',
                    '--type' => 'object_state',
                    '--mode' => $mode,
                    '--match-type' => 'object_state_identifier',
                    '--match-value' => (string) $matchValue,
                    'bundle' => $this->destination,
                    'name' => $name,
                ];
                $command = 'kaliop:migration:generate';
            } elseif ($this->mode === 'ibexa') {
                $now = new \DateTime();
                $inputArray = [
                    '--format' => 'yaml',
                    '--type' => 'object_state',
                    '--mode' => $mode,
                    '--match-property' => 'object_state_identifier',
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
            $this->logger->info('Object state migration generate process finished (' . $mode . ')', ['name' => $name, 'code' => $code, 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()]);
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
                $this->logger->error('Object state migration generation failed', ['code' => $code, 'error' => $process->getErrorOutput()]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate object state migration programmatically', ['mode' => $mode, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }
}
