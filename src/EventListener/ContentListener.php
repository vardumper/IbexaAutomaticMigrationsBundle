<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\EventListener;

use Ibexa\Contracts\Core\Repository\Events\ContentType\BeforeDeleteContentTypeEvent;
use Ibexa\Contracts\Core\Repository\Events\ContentType\PublishContentTypeDraftEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Process\Process;
use vardumper\IbexaAutomaticMigrationsBundle\Helper\Helper;

final class ContentTypeListener
{
    private bool $isCli = false;
    private ?string $mode = null;
    private string $projectDir;
    private string $destination;
    private ContainerInterface $container;
    private array $consoleCommand;

    public function __construct(
        private readonly LoggerInterface $logger,
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

    #[AsEventListener(PublishContentTypeDraftEvent::class)]
    public function onIbexaPublishContentTypeDraft(PublishContentTypeDraftEvent $event): void
    {
        $this->logger->info('IbexaAutomaticMigrationsBundle: PublishContentTypeDraftEvent received', ['event' => get_class($event)]);

        // Skip in CLI to prevent creating redundant migrations when executing migrations that create/update content types
        if ($this->isCli) {
            $this->logger->info('IbexaAutomaticMigrationsBundle: Skipping in CLI to avoid redundant migrations during execution');
            return;
        }

        $contentTypeDraft = $event->getContentTypeDraft();
        $this->logger->info('PublishContentTypeDraftEvent received', ['id' => $contentTypeDraft->id, 'identifier' => $contentTypeDraft->identifier]);

        // Load the published content type (with the new values after publishing)
        $contentTypeService = $this->container->get('ibexa.api.service.content_type');
        try {
            $publishedContentType = $contentTypeService->loadContentType($contentTypeDraft->id);
            $this->logger->info('Published content type loaded successfully', ['id' => $publishedContentType->id, 'identifier' => $publishedContentType->identifier]);
            
            // Check if a CREATE migration was ever executed for this identifier
            // This tells us if it's new or an update
            $hasExecutedCreate = $this->hasExecutedCreateMigration($publishedContentType->identifier);
            $mode = $hasExecutedCreate ? 'update' : 'create';
            
            $this->logger->info('Determined migration mode based on executed migrations', [
                'mode' => $mode,
                'identifier' => $publishedContentType->identifier,
                'has_executed_create' => $hasExecutedCreate
            ]);
            
            // Generate migration for both creates and updates (with the published/new values)
            $this->generateMigration($publishedContentType, $mode);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to process published content type', ['id' => $contentTypeDraft->id, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }

    #[AsEventListener(BeforeDeleteContentTypeEvent::class)]
    public function onIbexaBeforeDeleteContentType(BeforeDeleteContentTypeEvent $event): void
    {
        $this->logger->info('IbexaAutomaticMigrationsBundle: BeforeDeleteContentTypeEvent received', ['event' => get_class($event)]);

        // Skip in CLI to prevent creating redundant migrations when executing migrations that delete content types
        if ($this->isCli) {
            $this->logger->info('IbexaAutomaticMigrationsBundle: Skipping in CLI to avoid redundant migrations during execution');
            return;
        }

        $contentType = $event->getContentType();
        $this->logger->info('BeforeDeleteContentTypeEvent received', ['id' => $contentType->id, 'identifier' => $contentType->identifier]);

        // Generate delete migration BEFORE the content type is deleted
        $this->generateMigration($contentType, 'delete');
    }



    private function hasExecutedCreateMigration(string $identifier): bool
    {
        try {
            $conn = $this->container->get('doctrine.dbal.default_connection');
            
            if ($this->mode === 'kaliop') {
                $table = 'kaliop_migrations';
                $column = 'migration';
                $statusColumn = 'status';
                $executedStatus = 2; // STATUS_DONE
                
                // Check if any executed CREATE migration exists for this identifier
                $sql = "SELECT COUNT(*) as count FROM $table WHERE $column LIKE ? AND $statusColumn = ?";
                $result = $conn->executeQuery($sql, [
                    '%_content_type_create_' . $identifier . '.%',
                    $executedStatus,
                ])->fetchAssociative();
            } elseif ($this->mode === 'ibexa') {
                $table = 'ibexa_migrations';
                $column = 'name';
                $executedAtColumn = 'executed_at';
                
                // Check if any executed CREATE migration exists for this identifier
                // A migration is considered executed if executed_at is not null
                $sql = "SELECT COUNT(*) as count FROM $table WHERE $column LIKE ? AND $executedAtColumn IS NOT NULL";
                $result = $conn->executeQuery($sql, [
                    '%_auto_content_type_create_' . $identifier . '.%',
                ])->fetchAssociative();
            } else {
                // If mode is not set, assume no executed migration exists
                return false;
            }
            
            $count = (int)($result['count'] ?? 0);
            $this->logger->info('Checked for executed CREATE migrations', [
                'identifier' => $identifier,
                'mode' => $this->mode,
                'count' => $count
            ]);
            
            // Return true if any executed CREATE migration was found
            return $count > 0;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to check for executed CREATE migrations', [
                'exception' => $e->getMessage(),
                'mode' => $this->mode,
                'identifier' => $identifier
            ]);
            // If we can't check the DB, assume no executed migration exists (safer default)
            return false;
        }
    }

    private function generateMigration(\Ibexa\Contracts\Core\Repository\Values\ContentType\ContentType|\Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeDraft $contentType, string $mode): void
    {
        $this->logger->info('Starting migration generation', ['mode' => $mode, 'identifier' => $contentType->identifier, 'id' => $contentType->id]);
        
        if ($this->mode !== 'kaliop' && $this->mode !== 'ibexa') {
            $this->logger->info('Skipping migration generation - not using kaliop or ibexa mode', ['current_mode' => $this->mode]);
            return;
        }

        try {
            $matchValue = $contentType->identifier;
            $name = 'auto_content_type_' . $mode . '_' . (string) $matchValue;

            if ($this->mode === 'kaliop') {
                $inputArray = [
                    '--format' => 'yml',
                    '--type' => 'content_type',
                    '--mode' => $mode,
                    '--match-type' => 'content_type_identifier',
                    '--match-value' => (string) $matchValue,
                    'bundle' => $this->destination,
                    'name' => $name,
                ];
                $command = 'kaliop:migration:generate';
            } elseif ($this->mode === 'ibexa') {
                $now = new \DateTime();
                $inputArray = [
                    '--format' => 'yaml',
                    '--type' => 'content_type',
                    '--mode' => $mode,
                    '--match-property' => 'content_type_identifier',
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
            $this->logger->info('Migration generate process finished (' . $mode . ')', ['name' => $name, 'code' => $code, 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()]);
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
                $this->logger->error('Migration generation failed', ['code' => $code, 'error' => $process->getErrorOutput()]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate migration programmatically', ['mode' => $mode, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }
}
