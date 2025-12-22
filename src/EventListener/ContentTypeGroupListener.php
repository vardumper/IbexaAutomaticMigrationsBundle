<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\EventListener;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Ibexa\Contracts\Core\Repository\Events\ContentType\CreateContentTypeGroupEvent;
use Ibexa\Contracts\Core\Repository\Events\ContentType\UpdateContentTypeGroupEvent;
use Ibexa\Contracts\Core\Repository\Events\ContentType\DeleteContentTypeGroupEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

class ContentTypeGroupListener
{
    private const DESTINATION_KALIOP = 'src/MigrationsDefinitions';
    private const DESTINATION_IBEXA = 'src/Migrations/Ibexa/migrations';
    private bool $isCli = false;
    private ?string $mode = null;
    private string $projectDir;
    private string $destination;
    private array $consoleCommand;

    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        #[Autowire(service: 'service_container')]
        private readonly ContainerInterface $container
    )
    {
        $this->projectDir = rtrim($projectDir, DIRECTORY_SEPARATOR);
        $this->isCli = PHP_SAPI === 'cli';
        $this->consoleCommand = ['php', '-d', 'memory_limit=512M', $this->projectDir . '/bin/console'];
        if (class_exists('Ibexa\\Bundle\\Migration\\Command\\GenerateCommand')) {
            $this->mode = 'ibexa';
            $this->destination = $this->projectDir . DIRECTORY_SEPARATOR . self::DESTINATION_IBEXA;
        }
        if (class_exists('Kaliop\\IbexaMigrationBundle\\Command\\GenerateCommand')) {
            $this->mode = 'kaliop';
            $this->destination = $this->projectDir . DIRECTORY_SEPARATOR . self::DESTINATION_KALIOP;
        }
        if (!is_dir($this->destination)) {
            mkdir($this->destination, 0777, true);
        }
    }
    
    #[AsEventListener(CreateContentTypeGroupEvent::class)]
    public function onCreated(CreateContentTypeGroupEvent $event): void
    {
        if ($this->isCli && !isset($_SERVER['TEST_DELETE_MIGRATION'])) {
            return;
        }
        
        try {
            $matchValue = $event->getContentTypeGroupCreateStruct()->identifier;
            $name = 'auto_content_type_group_create_' . (string) $matchValue;

            if ($this->mode === 'kaliop') {
                $inputArray = [
                    '--format' => 'yml',
                    '--type' => 'content_type_group',
                    '--mode' => 'create',
                    '--match-type' => 'content_type_group_identifier',
                    '--match-value' => (string) $matchValue,
                    'bundle' => $this->destination,
                    'name' => $name,
                ];
                $command = 'kaliop:migration:generate';
            } elseif ($this->mode === 'ibexa') {
                $now = new \DateTime();
                $inputArray = [
                    '--format' => 'yaml',
                    '--type' => 'content_type_group',
                    '--mode' => 'create',
                    '--match-property' => 'content_type_group_identifier',
                    '--value' => (string) $matchValue,
                    '--file' => $now->format('Y_m_d_H_i_s_') . strtolower($name) . '.yaml',
                ];
                $command = 'ibexa:migrations:generate';
            } else {
                return;
            }

            $flags = [];
            foreach ($inputArray as $k => $v) {
                if (is_int($k)) {
                    $flags[] = $v;
                    continue;
                }
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
            $this->logger->info('Migration generate process finished (group create)', ['name' => $name, 'code' => $code, 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()]);

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
                $md5 = file_exists($fullPath) ? md5_file($fullPath) : null;
                try {
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
            $this->logger->error('Failed to generate migration programmatically (group create)', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }

    #[AsEventListener(UpdateContentTypeGroupEvent::class)]
    public function onUpdated(UpdateContentTypeGroupEvent $event): void
    {
        if ($this->isCli && !isset($_SERVER['TEST_DELETE_MIGRATION'])) {
            return;
        }
        $this->logger->info('ContentTypeGroup updated (listener)', ['id' => $event->getContentTypeGroup()->id, 'identifier' => $event->getContentTypeGroup()->identifier]);

        try {
            $matchValue = $event->getContentTypeGroup()->identifier;
            $name = 'auto_content_type_group_update_' . (string) $matchValue;

            if ($this->mode === 'kaliop') {
                $inputArray = [
                    '--format' => 'yml',
                    '--type' => 'content_type_group',
                    '--mode' => 'update',
                    '--match-type' => 'content_type_group_identifier',
                    '--match-value' => (string) $matchValue,
                    'bundle' => $this->destination,
                    'name' => $name,
                ];
                $command = 'kaliop:migration:generate';
            } elseif ($this->mode === 'ibexa') {
                $now = new \DateTime();
                $inputArray = [
                    '--format' => 'yaml',
                    '--type' => 'content_type_group',
                    '--mode' => 'update',
                    '--match-property' => 'content_type_group_identifier',
                    '--value' => (string) $matchValue,
                    '--file' => $now->format('Y_m_d_H_i_s_') . $name . '.yaml',
                ];
                $command = 'ibexa:migrations:generate';
            } else {
                return;
            }

            $flags = [];
            foreach ($inputArray as $k => $v) {
                if (is_int($k)) {
                    $flags[] = $v;
                    continue;
                }
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
            $this->logger->info('Migration generate process finished (group update)', ['name' => $name, 'code' => $code, 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()]);

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
                $md5 = file_exists($fullPath) ? md5_file($fullPath) : null;
                try {
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
            $this->logger->error('Failed to generate migration programmatically (group update)', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }

    #[AsEventListener(DeleteContentTypeGroupEvent::class)]
    public function onDeleted(DeleteContentTypeGroupEvent $event): void
    {
        if ($this->isCli && !isset($_SERVER['TEST_DELETE_MIGRATION'])) {
            return;
        }
        $this->logger->info('ContentTypeGroup deleted (listener)', ['id' => $event->getContentTypeGroup()->id, 'identifier' => $event->getContentTypeGroup()->identifier]);

        try {
            $matchValue = $event->getContentTypeGroup()->identifier;
            $name = 'auto_content_type_group_delete_' . (string) $matchValue;

            // For delete we create YAML directly (object may be gone), write .yaml for ibexa compatibility
            $timestamp = date('Y_m_d_H_i_s_');
            $fileName = $timestamp . $name . '.yaml';
            $data = [
                [
                    'type' => 'content_type_group',
                    'mode' => 'delete',
                    'match' => [
                        'content_type_group_identifier' => $matchValue
                    ]
                ]
            ];
            $yaml = Yaml::dump($data);
            $fullPath = $this->destination . DIRECTORY_SEPARATOR . $fileName;
            file_put_contents($fullPath, $yaml);
            try {
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
            $this->logger->error('Failed to generate migration programmatically (group delete)', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }
}
