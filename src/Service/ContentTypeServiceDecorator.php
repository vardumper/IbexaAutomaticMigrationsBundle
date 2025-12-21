<?php

declare(strict_types=1);

namespace IbexaAutomaticMigrationsBundle\Service;

use Ibexa\Contracts\Core\Repository\ContentTypeService;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroupCreateStruct;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroup;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentType;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeCreateStruct;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeDraft;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeUpdateStruct;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroupUpdateStruct;
use Ibexa\Contracts\Core\Repository\Values\ContentType\FieldDefinition;
use Ibexa\Contracts\Core\Repository\Values\ContentType\FieldDefinitionCreateStruct;
use Ibexa\Contracts\Core\Repository\Values\ContentType\FieldDefinitionUpdateStruct;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use IbexaAutomaticMigrationsBundle\Event\GenerateDeleteMigrationEvent;

class ContentTypeServiceDecorator implements ContentTypeService
{
    public function __construct(
        private readonly ContentTypeService $inner,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
        $this->logger->info('ContentTypeServiceDecorator constructed');
    }

    public function deleteContentType(ContentType $contentType): void
    {
        $this->logger->info('ContentTypeServiceDecorator: deleteContentType called', ['id' => $contentType->id, 'identifier' => $contentType->identifier]);

        // Dispatch event to generate delete migration BEFORE deleting the content type
        $event = new GenerateDeleteMigrationEvent($contentType);
        $this->eventDispatcher->dispatch($event);

        $this->inner->deleteContentType($contentType);
    }

    public function createContentTypeGroup(ContentTypeGroupCreateStruct $contentTypeGroupCreateStruct): ContentTypeGroup
    {
        return $this->inner->createContentTypeGroup($contentTypeGroupCreateStruct);
    }

    public function loadContentTypeGroup($contentTypeGroupId, array $prioritizedLanguages = []): ContentTypeGroup
    {
        return $this->inner->loadContentTypeGroup($contentTypeGroupId, $prioritizedLanguages);
    }

    public function loadContentTypeGroupByIdentifier($identifier, array $prioritizedLanguages = []): ContentTypeGroup
    {
        return $this->inner->loadContentTypeGroupByIdentifier($identifier, $prioritizedLanguages);
    }

    public function loadContentTypeGroups(array $prioritizedLanguages = []): iterable
    {
        return $this->inner->loadContentTypeGroups($prioritizedLanguages);
    }

    public function deleteContentTypeGroup(ContentTypeGroup $contentTypeGroup): void
    {
        $this->inner->deleteContentTypeGroup($contentTypeGroup);
    }

    public function updateContentTypeGroup(ContentTypeGroup $contentTypeGroup, ContentTypeGroupUpdateStruct $contentTypeGroupUpdateStruct): void
    {
        $this->inner->updateContentTypeGroup($contentTypeGroup, $contentTypeGroupUpdateStruct);
    }

    public function createContentType(ContentTypeCreateStruct $contentTypeCreateStruct, array $contentTypeGroups): ContentType
    {
        return $this->inner->createContentType($contentTypeCreateStruct, $contentTypeGroups);
    }

    public function loadContentType($contentTypeId, array $prioritizedLanguages = []): ContentType
    {
        return $this->inner->loadContentType($contentTypeId, $prioritizedLanguages);
    }

    public function loadContentTypeByIdentifier($identifier, array $prioritizedLanguages = []): ContentType
    {
        return $this->inner->loadContentTypeByIdentifier($identifier, $prioritizedLanguages);
    }

    public function loadContentTypeByRemoteId($remoteId, array $prioritizedLanguages = []): ContentType
    {
        return $this->inner->loadContentTypeByRemoteId($remoteId, $prioritizedLanguages);
    }

    public function loadContentTypes(array $prioritizedLanguages = []): iterable
    {
        return $this->inner->loadContentTypes($prioritizedLanguages);
    }

    public function createContentTypeDraft(ContentType $contentType): ContentTypeDraft
    {
        return $this->inner->createContentTypeDraft($contentType);
    }

    public function loadContentTypeDraft($contentTypeDraftId, array $prioritizedLanguages = []): ContentTypeDraft
    {
        return $this->inner->loadContentTypeDraft($contentTypeDraftId, $prioritizedLanguages);
    }

    public function loadContentTypeDrafts(): iterable
    {
        return $this->inner->loadContentTypeDrafts();
    }

    public function updateContentTypeDraft(ContentTypeDraft $contentTypeDraft, ContentTypeUpdateStruct $contentTypeUpdateStruct): ContentTypeDraft
    {
        return $this->inner->updateContentTypeDraft($contentTypeDraft, $contentTypeUpdateStruct);
    }

    public function deleteContentTypeDraft(ContentTypeDraft $contentTypeDraft): void
    {
        $this->inner->deleteContentTypeDraft($contentTypeDraft);
    }

    public function publishContentTypeDraft(ContentTypeDraft $contentTypeDraft): void
    {
        $this->inner->publishContentTypeDraft($contentTypeDraft);
    }

    public function newContentTypeCreateStruct($identifier): ContentTypeCreateStruct
    {
        return $this->inner->newContentTypeCreateStruct($identifier);
    }

    public function newContentTypeUpdateStruct(): ContentTypeUpdateStruct
    {
        return $this->inner->newContentTypeUpdateStruct();
    }

    public function newContentTypeGroupCreateStruct($identifier): ContentTypeGroupCreateStruct
    {
        return $this->inner->newContentTypeGroupCreateStruct($identifier);
    }

    public function newContentTypeGroupUpdateStruct(): ContentTypeGroupUpdateStruct
    {
        return $this->inner->newContentTypeGroupUpdateStruct();
    }

    public function newFieldDefinitionCreateStruct($identifier, $fieldTypeIdentifier): FieldDefinitionCreateStruct
    {
        return $this->inner->newFieldDefinitionCreateStruct($identifier, $fieldTypeIdentifier);
    }

    public function newFieldDefinitionUpdateStruct(): FieldDefinitionUpdateStruct
    {
        return $this->inner->newFieldDefinitionUpdateStruct();
    }

    public function isContentTypeUsed(ContentType $contentType): bool
    {
        return $this->inner->isContentTypeUsed($contentType);
    }

    public function getContentTypeUserRelations(ContentType $contentType, $offset = 0, $limit = -1): array
    {
        return $this->inner->getContentTypeUserRelations($contentType, $offset, $limit);
    }

    public function countContentTypeUserRelations(ContentType $contentType): int
    {
        return $this->inner->countContentTypeUserRelations($contentType);
    }

    public function removeContentTypeTranslation(ContentType $contentType, $languageCode): ContentType
    {
        return $this->inner->removeContentTypeTranslation($contentType, $languageCode);
    }

    public function copyContentType(ContentType $contentType, ContentTypeGroup $contentTypeGroup): ContentType
    {
        return $this->inner->copyContentType($contentType, $contentTypeGroup);
    }

    public function assignContentTypeGroup(ContentType $contentType, ContentTypeGroup $contentTypeGroup): void
    {
        $this->inner->assignContentTypeGroup($contentType, $contentTypeGroup);
    }

    public function unassignContentTypeGroup(ContentType $contentType, ContentTypeGroup $contentTypeGroup): void
    {
        $this->inner->unassignContentTypeGroup($contentType, $contentTypeGroup);
    }

    public function link($remoteId, ContentType $contentType): void
    {
        $this->inner->link($remoteId, $contentType);
    }

    public function getFieldDefinition($contentTypeId, $fieldDefinitionId, array $prioritizedLanguages = []): FieldDefinition
    {
        return $this->inner->getFieldDefinition($contentTypeId, $fieldDefinitionId, $prioritizedLanguages);
    }
}
