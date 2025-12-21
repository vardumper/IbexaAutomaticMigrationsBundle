<?php

use Ibexa\Contracts\Core\Repository\Repository;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeCreateStruct;
use Ibexa\Contracts\Core\Repository\Values\ContentType\FieldDefinitionCreateStruct;
use Symfony\Component\DependencyInjection\ContainerInterface;

test('delete migration is generated when content type is deleted', function () {
    // Set environment variable to allow CLI migration generation for testing
    $_SERVER['TEST_DELETE_MIGRATION'] = true;

    $identifier = 'test_delete_migration_' . time();

    // Get services from container
    $container = app(ContainerInterface::class);
    $repository = $container->get(Repository::class);

    // Create test content type
    $contentType = $repository->sudo(function (Repository $repo) use ($identifier) {
        // Set current user to admin within sudo
        $adminUser = $repo->getUserService()->loadUser(10);
        $repo->getPermissionResolver()->setCurrentUserReference($adminUser);

        $contentTypeService = $repo->getContentTypeService();
        $contentTypeCreateStruct = $contentTypeService->newContentTypeCreateStruct($identifier);
        $contentTypeCreateStruct->mainLanguageCode = 'eng-GB';
        $contentTypeCreateStruct->names = ['eng-GB' => 'Test Delete Migration'];
        $contentTypeCreateStruct->descriptions = ['eng-GB' => 'Test content type for delete migration'];
        $contentTypeCreateStruct->nameSchema = '<' . $identifier . '>';
        $contentTypeCreateStruct->isContainer = false;
        $contentTypeCreateStruct->defaultAlwaysAvailable = true;
        $contentTypeCreateStruct->defaultSortField = 2; // SORT_FIELD_PUBLISHED
        $contentTypeCreateStruct->defaultSortOrder = 0; // SORT_ORDER_DESC

        // Add a simple field
        $fieldDefinitionCreateStruct = $contentTypeService->newFieldDefinitionCreateStruct('title', 'ibexa_string');
        $fieldDefinitionCreateStruct->names = ['eng-GB' => 'Title'];
        $fieldDefinitionCreateStruct->descriptions = ['eng-GB' => 'Title field'];
        $fieldDefinitionCreateStruct->fieldGroup = 'content';
        $fieldDefinitionCreateStruct->position = 10;
        $fieldDefinitionCreateStruct->isTranslatable = true;
        $fieldDefinitionCreateStruct->isRequired = true;
        $fieldDefinitionCreateStruct->isSearchable = true;

        $contentTypeCreateStruct->addFieldDefinition($fieldDefinitionCreateStruct);

        $contentTypeGroup = $contentTypeService->loadContentTypeGroupByIdentifier('Content');
        return $contentTypeService->createContentType($contentTypeCreateStruct, [$contentTypeGroup]);
    });

    expect($contentType)->toBeInstanceOf(\Ibexa\Contracts\Core\Repository\Values\ContentType\ContentType::class);
    expect($contentType->id)->toBeGreaterThan(0);

    // Delete the content type
    $repository->sudo(function (Repository $repo) use ($contentType) {
        // Set current user to admin within sudo
        $adminUser = $repo->getUserService()->loadUser(10);
        $repo->getPermissionResolver()->setCurrentUserReference($adminUser);

        $contentTypeService = $repo->getContentTypeService();
        $contentTypeService->deleteContentType($contentType);
    });

    // Check if migration files were created
    $migrationDir = app()->getParameter('kernel.project_dir') . '/src/MigrationsDefinitions';
    $files = glob($migrationDir . '/*.yml');

    $deleteFiles = array_filter($files, fn($file) => str_contains($file, 'delete_' . $identifier));

    expect(count($deleteFiles))->toBe(1);

    $deleteFile = reset($deleteFiles);
    expect($deleteFile)->toBeString();
    expect(basename($deleteFile))->toContain('auto_content_type_delete_' . $identifier);

    // Verify the migration file content
    $yamlContent = file_get_contents($deleteFile);
    $migrationData = \Symfony\Component\Yaml\Yaml::parse($yamlContent);

    expect($migrationData)->toBeArray();
    expect($migrationData[0])->toHaveKey('type', 'content_type');
    expect($migrationData[0])->toHaveKey('mode', 'delete');
    expect($migrationData[0])->toHaveKey('identifier', $identifier);

})->skip('Requires full Ibexa setup and database');