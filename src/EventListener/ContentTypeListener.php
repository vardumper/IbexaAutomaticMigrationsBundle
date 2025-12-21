<?php

declare(strict_types=1);

namespace IbexaAutomaticMigrationsBundle\EventListener;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Ibexa\Contracts\Core\Repository\Events\ContentType\CreateContentTypeEvent;
use Ibexa\Contracts\Core\Repository\Events\ContentType\BeforeDeleteContentTypeEvent;
use Ibexa\Contracts\Core\Repository\Events\ContentType\PublishContentTypeDraftEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ContentTypeListener
{
    private const DESTINATION = 'src/MigrationsDefinitions';
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
    )
    {
        $this->container = $container;
        $this->projectDir = rtrim($projectDir, DIRECTORY_SEPARATOR);
        $this->destination = $this->projectDir . DIRECTORY_SEPARATOR . self::DESTINATION;
        $this->isCli = PHP_SAPI === 'cli';
        
        // Use php with increased memory limit to avoid memory issues
        $this->consoleCommand = ['php', '-d', 'memory_limit=512M', $this->projectDir . '/bin/console'];
        
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

    #[AsEventListener(PublishContentTypeDraftEvent::class)]
    public function onIbexaPublishContentTypeDraft(PublishContentTypeDraftEvent $event): void
    {
        $this->logger->info('IbexaAutomaticMigrationsBundle: PublishContentTypeDraftEvent received', ['event' => get_class($event)]);

        if ($this->isCli && !isset($_SERVER['TEST_DELETE_MIGRATION'])) {
            $this->logger->info('IbexaAutomaticMigrationsBundle: Skipping because running in CLI');
            return;
        }

        $contentTypeDraft = $event->getContentTypeDraft();
        $this->logger->info('Ibexa PublishContentTypeDraftEvent received', ['id' => $contentTypeDraft->id, 'identifier' => $contentTypeDraft->identifier]);

        // After publishing, try to load the published content type by ID instead of identifier
        // since the identifier might change during publishing
        $contentTypeService = $this->container->get('ibexa.api.service.content_type');
        try {
            $publishedContentType = $contentTypeService->loadContentType($contentTypeDraft->id);
            $this->logger->info('Published content type loaded successfully', ['id' => $publishedContentType->id, 'identifier' => $publishedContentType->identifier]);
            
            // Determine if this is a new content type or an update to an existing one
            // Check if there are existing migration files for this content type identifier
            $existingFiles = glob($this->destination . DIRECTORY_SEPARATOR . '*_content_type_*_' . $publishedContentType->identifier . '.yml');
            $isNewContentType = empty($existingFiles);
            $mode = $isNewContentType ? 'create' : 'update';
            
            $this->logger->info('Determined migration mode based on existing files', [
                'mode' => $mode, 
                'identifier' => $publishedContentType->identifier,
                'existing_files_count' => count($existingFiles),
                'is_new' => $isNewContentType
            ]);
            
            $this->generateMigration($publishedContentType, $mode);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load published content type by ID', ['id' => $contentTypeDraft->id, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }

    #[AsEventListener(BeforeDeleteContentTypeEvent::class)]
    public function onIbexaBeforeDeleteContentType(BeforeDeleteContentTypeEvent $event): void
    {
        $this->logger->info('IbexaAutomaticMigrationsBundle: BeforeDeleteContentTypeEvent received', ['event' => get_class($event)]);

        if ($this->isCli && !isset($_SERVER['TEST_DELETE_MIGRATION'])) {
            $this->logger->info('IbexaAutomaticMigrationsBundle: Skipping because running in CLI');
            return;
        }

        $contentType = $event->getContentType();
        $this->logger->info('BeforeDeleteContentTypeEvent received', ['id' => $contentType->id, 'identifier' => $contentType->identifier]);

        // Generate delete migration BEFORE the content type is deleted
        $this->generateDeleteMigration($contentType);
    }

    private function generateDeleteMigration(\Ibexa\Contracts\Core\Repository\Values\ContentType\ContentType $contentType): void
    {
        $this->logger->info('Generating delete migration for content type', ['id' => $contentType->id, 'identifier' => $contentType->identifier]);

        $timestamp = date('YmdHis');
        $filename = $timestamp . '_auto_content_type_delete_' . $contentType->identifier . '.yml';
        $filepath = $this->destination . DIRECTORY_SEPARATOR . $filename;

        // Create the delete migration content
        $migrationContent = [
            [
                'type' => 'content_type',
                'mode' => 'delete',
                'identifier' => $contentType->identifier,
            ]
        ];

        $yamlContent = \Symfony\Component\Yaml\Yaml::dump($migrationContent);

        if (file_put_contents($filepath, $yamlContent) === false) {
            $this->logger->error('Failed to write delete migration file', ['filepath' => $filepath]);
            return;
        }

        $this->logger->info('Delete migration file created', ['filepath' => $filepath]);

        // Mark migration as executed
        $md5 = md5_file($filepath);
        try {
            $conn = $this->container->get('doctrine.dbal.default_connection');
            $conn->insert('kaliop_migrations', [
                'migration' => $filename,
                'md5' => $md5,
                'path' => $filepath,
                'execution_date' => time(),
                'status' => 2,
                'execution_error' => null
            ]);
            $this->logger->info('Delete migration marked as executed', ['filename' => $filename]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to mark delete migration as executed', ['exception' => $e->getMessage()]);
        }
    }

    private function generateMigration(\Ibexa\Contracts\Core\Repository\Values\ContentType\ContentType|\Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeDraft $contentType, string $mode): void
    {
        $this->logger->info('Starting migration generation', ['mode' => $mode, 'identifier' => $contentType->identifier, 'id' => $contentType->id]);
        
        if ($this->mode !== 'kaliop') {
            $this->logger->info('Skipping migration generation - not using kaliop mode', ['current_mode' => $this->mode]);
            return;
        }

        try {
            $matchValue = $contentType->identifier;
            $name = 'auto_content_type_' . $mode . '_' . (string) $matchValue;

            $inputArray = [
                '--format' => 'yml',
                '--type' => 'content_type',
                '--mode' => $mode,
                '--match-type' => 'content_type_identifier',
                '--match-value' => (string) $matchValue,
                'bundle' => $this->destination,
                'name' => $name,
            ];

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
            $cmd = array_merge($this->consoleCommand, [
                'kaliop:migration:generate',
            ], $flags, [$this->destination, $name]);
            $process = new Process($cmd, $this->projectDir);

            $process->run();
            $code = $process->getExitCode();
            $this->logger->info('Migration generate process finished (' . $mode . ')', ['name' => $name, 'code' => $code, 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()]);
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
                        $this->logger->info('Migration marked as executed', ['filename' => $fileName]);
                    } catch (\Throwable $e) {
                        $this->logger->warning('Failed to mark migration as executed', ['exception' => $e->getMessage()]);
                    }
                }
            } else {
                $this->logger->error('Migration generation failed', ['code' => $code, 'error' => $process->getErrorOutput()]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate migration programmatically', ['mode' => $mode, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }
}
