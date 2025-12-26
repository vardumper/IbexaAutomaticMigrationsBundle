<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\EventListener;

use Doctrine\DBAL\Connection;
use Ibexa\Contracts\Core\Repository\Events\ObjectState\CreateObjectStateGroupEvent;
use Ibexa\Contracts\Core\Repository\Events\ObjectState\DeleteObjectStateGroupEvent;
use Ibexa\Contracts\Core\Repository\Events\ObjectState\UpdateObjectStateGroupEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use vardumper\IbexaAutomaticMigrationsBundle\Helper\Helper;
use vardumper\IbexaAutomaticMigrationsBundle\Service\SettingsService;

final class ObjectStateGroupListener implements EventSubscriberInterface
{
    private bool $isCli = false;
    private ?string $mode = null;
    private string $projectDir;
    private string $destination;
    /** @var array<string> */
    private array $consoleCommand;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settingsService,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        #[Autowire(service: 'service_container')]
        private readonly ContainerInterface $container
    ) {
        $this->logger->info('ObjectStateGroupListener constructor called');
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
            CreateObjectStateGroupEvent::class => 'onCreated',
            UpdateObjectStateGroupEvent::class => 'onUpdated',
            DeleteObjectStateGroupEvent::class => 'onDeleted',
        ];
    }

    public function onCreated(CreateObjectStateGroupEvent $event): void
    {
        $this->logger->info('ObjectStateGroupListener onCreated called', [
            'event' => get_class($event),
            'enabled' => $this->settingsService->isEnabled(),
            'object_state_group_enabled' => $this->settingsService->isTypeEnabled('object_state_group'),
            'is_cli' => $this->isCli
        ]);

        if (!$this->settingsService->isEnabled() || !$this->settingsService->isTypeEnabled('object_state_group')) {
            $this->logger->info('ObjectStateGroupListener: settings not enabled');
            return;
        }

        if ($this->isCli) {
            $this->logger->info('ObjectStateGroupListener: running in CLI, skipping');
            return;
        }

        try {
            $matchValue = $event->getObjectStateGroupCreateStruct()->identifier;
            $name = 'auto_objectstategroup_create_' . (string) $matchValue;
            $fileName = '';
            $inputArray = [];

            if ($this->mode === 'ibexa') {
                $fileName = (new \DateTime())->format('Y_m_d_H_i_s_') . strtolower($name) . '.yaml';
                $inputArray = [
                    '--format' => 'yaml',
                    '--type' => 'object_state_group',
                    '--mode' => 'create',
                    '--match-property' => 'object_state_group_identifier',
                    '--value' => (string) $matchValue,
                    '--file' => $fileName,
                ];
                $command = 'ibexa:migrations:generate';
            } else {
                $inputArray = [
                    '--format' => 'yml',
                    '--type' => 'object_state_group',
                    '--mode' => 'create',
                    '--match-type' => 'object_state_group_identifier',
                    '--match-value' => (string) $matchValue,
                    'bundle' => $this->destination,
                    'name' => $name,
                ];
                $command = 'kaliop:migration:generate';
            }

            $flags = [];
            foreach ($inputArray as $k => $v) {
                if (str_starts_with((string) $k, '--') || str_starts_with((string) $k, '-')) {
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
            $this->logger->info('ObjectStateGroup migration generate process finished (create)', ['name' => $name, 'code' => $code, 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()]);

            if ($code == 0) {
                if ($this->mode === 'ibexa') {
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
                $md5 = file_exists($fullPath) ? md5_file($fullPath) : null;
                try {
                    $affected = 0;
                    $table = '';
                    /** @var Connection $conn */
                    $conn = $this->container->get('doctrine.dbal.default_connection');
                    if ($this->mode === 'ibexa') {
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
                        $table = 'kaliop_migrations';
                        $data = [
                            'execution_date' => time(),
                            'status' => 2
                        ];
                        $identifier = ['migration' => $fileName];
                        $affected = $conn->update($table, $data, $identifier);
                        if ($affected === 0) {
                            // Row not found, insert
                            $conn->insert($table, array_merge($identifier, $data, [
                                'md5' => $md5,
                                'path' => $fullPath,
                                'execution_error' => null
                            ]));
                        }
                    }
                    $this->logger->info('Migration marked as executed', ['filename' => $fileName, 'table' => $table, 'action' => $affected > 0 ? 'updated' : 'inserted']);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to mark migration as executed', ['exception' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate migration programmatically (object state group create)', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }

    public function onUpdated(UpdateObjectStateGroupEvent $event): void
    {
        if (!$this->settingsService->isEnabled() || !$this->settingsService->isTypeEnabled('object_state_group')) {
            return;
        }

        if ($this->isCli) {
            return;
        }

        $this->logger->info('ObjectStateGroup updated (listener)', ['id' => $event->getObjectStateGroup()->id, 'identifier' => $event->getObjectStateGroup()->identifier]);

        try {
            $matchValue = $event->getObjectStateGroup()->identifier;
            $name = 'auto_objectstategroup_update_' . (string) $matchValue;
            $fileName = '';
            $inputArray = [];

            if ($this->mode === 'ibexa') {
                $fileName = (new \DateTime())->format('Y_m_d_H_i_s_') . $name . '.yaml';
                $inputArray = [
                    '--format' => 'yaml',
                    '--type' => 'object_state_group',
                    '--mode' => 'update',
                    '--match-property' => 'object_state_group_identifier',
                    '--value' => (string) $matchValue,
                    '--file' => $fileName,
                ];
                $command = 'ibexa:migrations:generate';
            } else {
                $inputArray = [
                    '--format' => 'yml',
                    '--type' => 'object_state_group',
                    '--mode' => 'update',
                    '--match-type' => 'object_state_group_identifier',
                    '--match-value' => (string) $matchValue,
                    'bundle' => $this->destination,
                    'name' => $name,
                ];
                $command = 'kaliop:migration:generate';
            }

            $flags = [];
            foreach ($inputArray as $k => $v) {
                if (str_starts_with((string) $k, '--') || str_starts_with((string) $k, '-')) {
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
            $this->logger->info('ObjectStateGroup migration generate process finished (update)', ['name' => $name, 'code' => $code, 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()]);

            if ($code == 0) {
                if ($this->mode === 'ibexa') {
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
                $md5 = file_exists($fullPath) ? md5_file($fullPath) : null;
                try {
                    $affected = 0;
                    $table = '';
                    /** @var Connection $conn */
                    $conn = $this->container->get('doctrine.dbal.default_connection');
                    if ($this->mode === 'ibexa') {
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
                        $table = 'kaliop_migrations';
                        $data = [
                            'execution_date' => time(),
                            'status' => 2
                        ];
                        $identifier = ['migration' => $fileName];
                        $affected = $conn->update($table, $data, $identifier);
                        if ($affected === 0) {
                            // Row not found, insert
                            $conn->insert($table, array_merge($identifier, $data, [
                                'md5' => $md5,
                                'path' => $fullPath,
                                'execution_error' => null
                            ]));
                        }
                    }
                    $this->logger->info('Migration marked as executed', ['filename' => $fileName, 'table' => $table, 'action' => $affected > 0 ? 'updated' : 'inserted']);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to mark migration as executed', ['exception' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate migration programmatically (object state group update)', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }

    public function onDeleted(DeleteObjectStateGroupEvent $event): void
    {
        if (!$this->settingsService->isEnabled() || !$this->settingsService->isTypeEnabled('object_state_group')) {
            return;
        }

        if ($this->isCli) {
            return;
        }

        $this->logger->info('ObjectStateGroup deleted (listener)', ['id' => $event->getObjectStateGroup()->id, 'identifier' => $event->getObjectStateGroup()->identifier]);

        try {
            $matchValue = $event->getObjectStateGroup()->identifier;
            $name = 'auto_objectstategroup_delete_' . (string) $matchValue;

            // For delete we create YAML directly (object may be gone), write .yaml for ibexa compatibility
            $timestamp = date('Y_m_d_H_i_s_');
            $fileName = $timestamp . $name . '.yaml';
            $data = [
                [
                    'type' => 'object_state_group',
                    'mode' => 'delete',
                    'match' => [
                        'object_state_group_identifier' => $matchValue
                    ]
                ]
            ];
            $yaml = Yaml::dump($data);
            $fullPath = $this->destination . DIRECTORY_SEPARATOR . $fileName;
            file_put_contents($fullPath, $yaml);
            try {
                /** @var Connection $conn */
                $conn = $this->container->get('doctrine.dbal.default_connection');
                $affected = 0;
                $table = '';
                if ($this->mode === 'ibexa') {
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
                    $table = 'kaliop_migrations';
                    $data = [
                        'execution_date' => time(),
                        'status' => 2
                    ];
                    $identifier = ['migration' => $fileName];
                    $affected = $conn->update($table, $data, $identifier);
                    if ($affected === 0) {
                        // Row not found, insert
                        $conn->insert($table, array_merge($identifier, $data, [
                            'md5' => file_exists($fullPath) ? md5_file($fullPath) : null,
                            'path' => $fullPath,
                            'execution_error' => null
                        ]));
                    }
                }
                $this->logger->info('Migration marked as executed', ['filename' => $fileName, 'table' => $table, 'action' => $affected > 0 ? 'updated' : 'inserted']);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to mark migration as executed', ['exception' => $e->getMessage()]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate migration programmatically (object state group delete)', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }
}
