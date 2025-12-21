<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\Service;

use Ibexa\Contracts\Core\Repository\ContentTypeService;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroupCreateStruct;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroup;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentType;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ContentTypeServiceDecorator implements ContentTypeService
{
    public function __construct(
        private readonly ContentTypeService $inner,
        private readonly LoggerInterface $logger,
        private readonly ContainerInterface $container
    ) {
        $this->logger->info('ContentTypeServiceDecorator constructed');
    }

    public function deleteContentType(ContentType $contentType): void
    {
        $this->logger->info('ContentTypeServiceDecorator: deleteContentType called', ['id' => $contentType->id, 'identifier' => $contentType->identifier]);

        // Generate delete migration BEFORE deleting the content type
        $this->generateDeleteMigration($contentType);

        $this->inner->deleteContentType($contentType);
    }

    private function generateDeleteMigration(ContentType $contentType): void
    {
        $this->logger->info('Generating delete migration for content type', ['id' => $contentType->id, 'identifier' => $contentType->identifier]);

        $projectDir = dirname(__DIR__, 3); // Go up from Service to src to bundle root
        $destination = $projectDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'MigrationsDefinitions';

        if (!is_dir($destination)) {
            $this->logger->error('Migrations destination directory does not exist', ['path' => $destination]);
            return;
        }

        $timestamp = date('YmdHis');
        $filename = $timestamp . '_auto_content_type_delete_' . $contentType->identifier . '.yml';
        $filepath = $destination . DIRECTORY_SEPARATOR . $filename;

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

    public function createContentTypeGroup(ContentTypeGroupCreateStruct $contentTypeGroupCreateStruct): ContentTypeGroup
    {
        return $this->inner->createContentTypeGroup($contentTypeGroupCreateStruct);
    }
}
