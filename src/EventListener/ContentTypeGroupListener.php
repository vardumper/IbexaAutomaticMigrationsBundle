<?php

declare(strict_types=1);

namespace IbexaAutomaticMigrationsBundle\EventListener;

use Symfony\Component\DependencyInjection\ContainerInterface;
use IbexaAutomaticMigrationsBundle\Event\ContentTypeGroupCreatedEvent;
use IbexaAutomaticMigrationsBundle\Event\ContentTypeGroupUpdatedEvent;
use IbexaAutomaticMigrationsBundle\Event\ContentTypeGroupDeletedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

class ContentTypeGroupListener
{
    private const REL_DESTINATION = 'src/MigrationsDefinitions';
    private bool $isCli = false;
    private ?string $mode = null;
    private string $projectDir;
    private string $destination;
    private static array $generatedMigrations = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        #[Autowire(service: 'service_container')]
        ContainerInterface $container
    )
    {
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

    #[AsEventListener(ContentTypeGroupCreatedEvent::class)]
    public function onCreated(ContentTypeGroupCreatedEvent $event): void
    {
        if ($this->isCli) {
            return;
        }

        if ($this->mode === 'kaliop') {
            try {
                $matchValue = $event->contentTypeGroup->identifier;
                $name = 'auto_content_type_group_create_' . (string) $matchValue;

                if (in_array($name, self::$generatedMigrations)) {
                    return;
                }
                self::$generatedMigrations[] = $name;

                $inputArray = [
                    '--format' => 'yml',
                    '--type' => 'content_type_group',
                    '--mode' => 'create',
                    '--match-type' => 'content_type_group_identifier',
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
                $process->run();
                $code = $process->getExitCode();
                $this->logger->info('Migration generate process finished (group create)', ['name' => $name, 'code' => $code, 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()]);
                if ($code == 0) {
                    if (preg_match('/Generated new migration file: .*\/([^\/]+\.yml)/', $process->getOutput(), $matches)) {
                        $fileName = $matches[1];
                        try {
                            $conn = $this->container->get('doctrine.dbal.default_connection');
                            $conn->insert('migration_versions', ['version' => $fileName, 'executed_at' => date('Y-m-d H:i:s')]);
                        } catch (\Throwable $e) {
                            $this->logger->warning('Failed to mark migration as executed', ['exception' => $e->getMessage()]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to generate migration programmatically (group create)', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            }
        }
    }

    #[AsEventListener(ContentTypeGroupUpdatedEvent::class)]
    public function onUpdated(ContentTypeGroupUpdatedEvent $event): void
    {
        if ($this->isCli) {
            return;
        }
        $this->logger->info('ContentTypeGroup updated (listener)', ['id' => $event->contentTypeGroup->id, 'identifier' => $event->contentTypeGroup->identifier]);

        if ($this->mode === 'kaliop') {
            try {
                $matchValue = $event->contentTypeGroup->identifier;
                $name = 'auto_content_type_group_update_' . (string) $matchValue;

                if (in_array($name, self::$generatedMigrations)) {
                    return;
                }
                self::$generatedMigrations[] = $name;

                $inputArray = [
                    '--format' => 'yml',
                    '--type' => 'content_type_group',
                    '--mode' => 'update',
                    '--match-type' => 'content_type_group_identifier',
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
                $process->run();
                $code = $process->getExitCode();
                $this->logger->info('Migration generate process finished (group update)', ['name' => $name, 'code' => $code, 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()]);
                if ($code == 0) {
                    if (preg_match('/Generated new migration file: .*\/([^\/]+\.yml)/', $process->getOutput(), $matches)) {
                        $fileName = $matches[1];
                        try {
                            $conn = $this->container->get('doctrine.dbal.default_connection');
                            $conn->insert('migration_versions', ['version' => $fileName, 'executed_at' => date('Y-m-d H:i:s')]);
                        } catch (\Throwable $e) {
                            $this->logger->warning('Failed to mark migration as executed', ['exception' => $e->getMessage()]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to generate migration programmatically (group update)', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            }
        }
    }

    #[AsEventListener(ContentTypeGroupDeletedEvent::class)]
    public function onDeleted(ContentTypeGroupDeletedEvent $event): void
    {
        if ($this->isCli) {
            return;
        }
        $this->logger->info('ContentTypeGroup deleted (listener)', ['id' => $event->contentTypeGroup->id, 'identifier' => $event->contentTypeGroup->identifier]);

        if ($this->mode === 'kaliop') {
            try {
                $matchValue = $event->contentTypeGroup->identifier;
                $name = 'auto_content_type_group_delete_' . (string) $matchValue;

                if (in_array($name, self::$generatedMigrations)) {
                    return;
                }
                self::$generatedMigrations[] = $name;

                // For delete, generate YAML directly since content type group may be gone
                $timestamp = date('YmdHis');
                $fileName = $timestamp . '_' . $name . '.yml';
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
                file_put_contents($this->destination . DIRECTORY_SEPARATOR . $fileName, $yaml);
                try {
                    $conn = $this->container->get('doctrine.dbal.default_connection');
                    $conn->insert('migration_versions', ['version' => $fileName, 'executed_at' => date('Y-m-d H:i:s')]);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to mark migration as executed', ['exception' => $e->getMessage()]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Failed to generate migration programmatically (group delete)', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            }
        }
    }
}
