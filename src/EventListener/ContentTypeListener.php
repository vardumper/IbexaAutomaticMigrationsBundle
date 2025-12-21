<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\EventListener;

use Symfony\Component\DependencyInjection\ContainerInterface;
use vardumper\IbexaAutomaticMigrationsBundle\Event\ContentTypeCreatedEvent;
use vardumper\IbexaAutomaticMigrationsBundle\Event\ContentTypeUpdatedEvent;
use vardumper\IbexaAutomaticMigrationsBundle\Event\ContentTypeDeletedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

class ContentTypeListener
{
    private const REL_DESTINATION = 'src/MigrationsDefinitions';
    private bool $isCli = false;
    private ?string $mode = null;
    private string $projectDir;
    private string $destination;
    private ContainerInterface $container;
    // private static array $generatedMigrations = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        #[Autowire(service: 'service_container')]
        ContainerInterface $container
    )
    {
        $this->container = $container;
        $this->projectDir = rtrim($projectDir, DIRECTORY_SEPARATOR);
        $this->destination = $this->projectDir . DIRECTORY_SEPARATOR . self::REL_DESTINATION;
        $this->isCli = PHP_SAPI === 'cli';
        if (class_exists('Ibexa\\Bundle\\Migration\\Command\\GenerateCommand')) {
            $this->mode = 'ibexa';
        }
        if (class_exists('Kaliop\\IbexaMigrationBundle\\Command\\GenerateCommand')) {
            $this->mode = 'kaliop';
        }
        if (!is_dir($this->destination)) {
            mkdir($this->destination, 0777, true);
        }
    }

    #[AsEventListener(ContentTypeCreatedEvent::class)]
    public function onCreated(ContentTypeCreatedEvent $event): void
    {
        if ($this->isCli) {
            return;
        }

        $this->logger->info('ContentType created (listener)', ['identifier' => $event->identifier]);

        if ($this->mode === 'kaliop') {
            try {
                $matchValue = $event->identifier;
                $name = 'auto_content_type_create_' . (string) $matchValue;

                // if (in_array($name, self::$generatedMigrations)) {
                //     return;
                // }
                // self::$generatedMigrations[] = $name;

                $inputArray = [
                    '--format' => 'yml',
                    '--type' => 'content_type',
                    '--mode' => 'create',
                    '--match-type' => 'content_type_identifier',
                    '--match-value' => (string) $matchValue,
                    'bundle' => $this->destination,
                    'name' => $name,
                ];

                $phpBinary = 'php';
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
                $cmd = array_merge([
                    $phpBinary,
                    $this->projectDir . '/bin/console',
                    'kaliop:migration:generate',
                ], $flags, [$this->destination, $name]);
                $process = new Process($cmd, $this->projectDir);

                // Commit the transaction so the child process can see the new content type
                $repository = $this->container->get('ibexa.api.repository');
                $repository->commit();

                $process->run();
                $code = $process->getExitCode();
                $this->logger->info('Migration generate process finished (create)', ['name' => $name, 'code' => $code, 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()]);
                if ($code == 0) {
                    if (preg_match('/Generated new migration file: .*\/([^\/]+\.yml)/', $process->getOutput(), $matches)) {
                        $fileName = $matches[1];
                        $fullPath = $this->destination . DIRECTORY_SEPARATOR . $fileName;
                        $md5 = md5_file($fullPath);
                        try {
                            $conn = $this->container->get('doctrine.dbal.default_connection');
                            $conn->insert('kaliop_migrations', [
                                'migration' => $fileName,
                                'md5' => $md5,
                                'path' => $fullPath,
                                'execution_date' => time(),
                                'status' => 2,
                                'execution_error' => null
                            ]);
                        } catch (\Throwable $e) {
                            $this->logger->warning('Failed to mark migration as executed', ['exception' => $e->getMessage()]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to generate migration programmatically (create)', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            }
        }
    }

    #[AsEventListener(ContentTypeUpdatedEvent::class)]
    public function onUpdated(ContentTypeUpdatedEvent $event): void
    {
        if ($this->isCli) {
            return;
        }
        $this->logger->info('ContentType updated (listener)', ['id' => $event->contentType->id, 'identifier' => $event->contentType->identifier]);

        if ($this->mode === 'kaliop') {
            try {
                $contentTypeService = $this->container->get('ibexa.api.service.content_type');
                $matchValue = $event->contentType->identifier;
                $name = 'auto_content_type_update_' . (string) $matchValue;

                // if (in_array($name, self::$generatedMigrations)) {
                //     return;
                // }
                // self::$generatedMigrations[] = $name;

                $inputArray = [
                    '--format' => 'yml',
                    '--type' => 'content_type',
                    '--mode' => 'update',
                    '--match-type' => 'content_type_identifier',
                    '--match-value' => (string) $matchValue,
                    'bundle' => $this->destination,
                    'name' => $name,
                ];

                $phpBinary = 'php';
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
                $cmd = array_merge([
                    $phpBinary,
                    $this->projectDir . '/bin/console',
                    'kaliop:migration:generate',
                ], $flags, [$this->destination, $name]);
                $process = new Process($cmd, $this->projectDir);

                // Commit the transaction so the child process can see the updated content type
                $repository = $this->container->get('ibexa.api.repository');
                $repository->commit();

                $process->run();
                $code = $process->getExitCode();
                $this->logger->info('Migration generate process finished (update)', ['name' => $name, 'code' => $code, 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()]);
                if ($code == 0) {
                    if (preg_match('/Generated new migration file: .*\/([^\/]+\.yml)/', $process->getOutput(), $matches)) {
                        $fileName = $matches[1];
                        $fullPath = $this->destination . DIRECTORY_SEPARATOR . $fileName;
                        $md5 = md5_file($fullPath);
                        try {
                            $conn = $this->container->get('doctrine.dbal.default_connection');
                            $conn->insert('kaliop_migrations', [
                                'migration' => $fileName,
                                'md5' => $md5,
                                'path' => $fullPath,
                                'execution_date' => time(),
                                'status' => 2,
                                'execution_error' => null
                            ]);
                        } catch (\Throwable $e) {
                            $this->logger->warning('Failed to mark migration as executed', ['exception' => $e->getMessage()]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to generate migration programmatically (update)', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            }
        }
    }

    #[AsEventListener(ContentTypeDeletedEvent::class)]
    public function onDeleted(ContentTypeDeletedEvent $event): void
    {
        if ($this->isCli) {
            return;
        }
        $this->logger->info('ContentType deleted (listener)', ['id' => $event->contentType->id, 'identifier' => $event->contentType->identifier]);

        if ($this->mode === 'kaliop') {
            try {
                $matchValue = $event->contentType->identifier ?? $event->contentType->id;
                $name = 'auto_content_type_delete_' . (string) $matchValue;

                // if (in_array($name, self::$generatedMigrations)) {
                //     return;
                // }
                // self::$generatedMigrations[] = $name;

                // For delete, generate YAML directly since content type may be gone
                $timestamp = date('YmdHis');
                $fileName = $timestamp . '_' . $name . '.yml';
                $data = [
                    [
                        'type' => 'content_type',
                        'mode' => 'delete',
                        'match' => [
                            'content_type_identifier' => $matchValue
                        ]
                    ]
                ];
                $yaml = Yaml::dump($data);
                file_put_contents($this->destination . DIRECTORY_SEPARATOR . $fileName, $yaml);
                try {
                    $conn = $this->container->get('doctrine.dbal.default_connection');
                    $conn->insert('kaliop_migrations', [
                        'migration' => $fileName,
                        'md5' => md5_file($this->destination . DIRECTORY_SEPARATOR . $fileName),
                        'path' => $this->destination . DIRECTORY_SEPARATOR . $fileName,
                        'execution_date' => time(),
                        'status' => 2,
                        'execution_error' => null
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to mark migration as executed', ['exception' => $e->getMessage()]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to generate migration programmatically (delete)', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            }
        }
    }
}
