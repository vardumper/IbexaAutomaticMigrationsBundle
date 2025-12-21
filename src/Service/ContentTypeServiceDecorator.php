<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\Service;

use Ibexa\Contracts\Core\Repository\ContentTypeService;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroupCreateStruct;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroupUpdateStruct;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroup;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeCreateStruct;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeDraft;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentType;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeUpdateStruct;
use Ibexa\Contracts\Core\Repository\Values\ContentType\FieldDefinition;
use Ibexa\Contracts\Core\Repository\Values\ContentType\FieldDefinitionCreateStruct;
use Ibexa\Contracts\Core\Repository\Values\ContentType\FieldDefinitionUpdateStruct;
use Ibexa\Contracts\Core\Repository\Exceptions\NotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use vardumper\IbexaAutomaticMigrationsBundle\Event\ContentTypeCreatedEvent;
use vardumper\IbexaAutomaticMigrationsBundle\Event\ContentTypeDeletedEvent;
use vardumper\IbexaAutomaticMigrationsBundle\Event\ContentTypeGroupCreatedEvent;
use vardumper\IbexaAutomaticMigrationsBundle\Event\ContentTypeGroupDeletedEvent;
use vardumper\IbexaAutomaticMigrationsBundle\Event\ContentTypeGroupUpdatedEvent;
use vardumper\IbexaAutomaticMigrationsBundle\Event\ContentTypeUpdatedEvent;

class ContentTypeServiceDecorator implements ContentTypeService
{
    public function __construct(
        private readonly ContentTypeService $inner,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger
    ) {
        $this->logger->info('ContentTypeServiceDecorator constructed');
    }

    public function __wakeup()
    {
        $this->logger->info('ContentTypeServiceDecorator __wakeup');
    }
    
    public static function __instantiate_probe(): void
    {
        error_log('ContentTypeServiceDecorator instantiated');
    }

    public function createContentTypeGroup(ContentTypeGroupCreateStruct $contentTypeGroupCreateStruct): ContentTypeGroup
    {
        $group = $this->inner->createContentTypeGroup($contentTypeGroupCreateStruct);
        $this->eventDispatcher->dispatch(new ContentTypeGroupCreatedEvent($group));
        return $group;
    }

    public function updateContentTypeGroup(ContentTypeGroup $contentTypeGroup, ContentTypeGroupUpdateStruct $contentTypeGroupUpdateStruct): void
    {
        $this->inner->updateContentTypeGroup($contentTypeGroup, $contentTypeGroupUpdateStruct);
        $this->eventDispatcher->dispatch(new ContentTypeGroupUpdatedEvent($contentTypeGroup));
    }

    public function deleteContentTypeGroup(ContentTypeGroup $contentTypeGroup): void
    {
        $this->eventDispatcher->dispatch(new ContentTypeGroupDeletedEvent($contentTypeGroup));
        $this->inner->deleteContentTypeGroup($contentTypeGroup);
    }

    public function loadContentTypeGroup(int $contentTypeGroupId, array $prioritizedLanguages = []): ContentTypeGroup
    {
        return $this->inner->loadContentTypeGroup($contentTypeGroupId, $prioritizedLanguages);
    }

    public function loadContentTypeGroupByIdentifier(string $contentTypeGroupIdentifier, array $prioritizedLanguages = []): ContentTypeGroup
    {
        return $this->inner->loadContentTypeGroupByIdentifier($contentTypeGroupIdentifier, $prioritizedLanguages);
    }

    public function loadContentTypeGroups(array $prioritizedLanguages = []): iterable
    {
        return $this->inner->loadContentTypeGroups($prioritizedLanguages);
    }

    public function createContentType(ContentTypeCreateStruct $contentTypeCreateStruct, array $contentTypeGroups): ContentTypeDraft
    {
        return $this->inner->createContentType($contentTypeCreateStruct, $contentTypeGroups);
    }

    public function loadContentType(int $contentTypeId, array $prioritizedLanguages = []): ContentType
    {
        return $this->inner->loadContentType($contentTypeId, $prioritizedLanguages);
    }

    public function loadContentTypeByIdentifier(string $identifier, array $prioritizedLanguages = []): ContentType
    {
        return $this->inner->loadContentTypeByIdentifier($identifier, $prioritizedLanguages);
    }

    public function loadContentTypeByRemoteId(string $remoteId, array $prioritizedLanguages = []): ContentType
    {
        return $this->inner->loadContentTypeByRemoteId($remoteId, $prioritizedLanguages);
    }

    public function loadContentTypeDraft(int $contentTypeId, bool $ignoreOwnership = false): ContentTypeDraft
    {
        
        return $this->inner->loadContentTypeDraft($contentTypeId, $ignoreOwnership);
    }

    public function loadContentTypeList(array $contentTypeIds, array $prioritizedLanguages = []): iterable
    {
        return $this->inner->loadContentTypeList($contentTypeIds, $prioritizedLanguages);
    }

    public function findContentTypes(?\Ibexa\Contracts\Core\Repository\Values\ContentType\Query\ContentTypeQuery $query = null, array $prioritizedLanguages = []): \Ibexa\Contracts\Core\Repository\Values\ContentType\SearchResult
    {
        return $this->inner->findContentTypes($query, $prioritizedLanguages);
    }

    public function loadContentTypes(ContentTypeGroup $contentTypeGroup, array $prioritizedLanguages = []): iterable
    {
        return $this->inner->loadContentTypes($contentTypeGroup, $prioritizedLanguages);
    }

    public function createContentTypeDraft(ContentType $contentType): ContentTypeDraft
    {
        return $this->inner->createContentTypeDraft($contentType);
    }

    public function updateContentTypeDraft(ContentTypeDraft $contentTypeDraft, ContentTypeUpdateStruct $contentTypeUpdateStruct): void
    {
        $this->inner->updateContentTypeDraft($contentTypeDraft, $contentTypeUpdateStruct);
    }

    public function deleteContentType(ContentType $contentType): void
    {
        $this->inner->deleteContentType($contentType);
        $this->eventDispatcher->dispatch(new ContentTypeDeletedEvent($contentType));
    }

    public function copyContentType(ContentType $contentType, ?\Ibexa\Contracts\Core\Repository\Values\User\User $creator = null): ContentType
    {
        return $this->inner->copyContentType($contentType, $creator);
    }

    public function assignContentTypeGroup(ContentType $contentType, ContentTypeGroup $contentTypeGroup): void
    {
        $this->inner->assignContentTypeGroup($contentType, $contentTypeGroup);
    }

    public function unassignContentTypeGroup(ContentType $contentType, ContentTypeGroup $contentTypeGroup): void
    {
        $this->inner->unassignContentTypeGroup($contentType, $contentTypeGroup);
    }

    public function addFieldDefinition(ContentTypeDraft $contentTypeDraft, FieldDefinitionCreateStruct $fieldDefinitionCreateStruct): void
    {
        $this->inner->addFieldDefinition($contentTypeDraft, $fieldDefinitionCreateStruct);
    }

    public function removeFieldDefinition(ContentTypeDraft $contentTypeDraft, FieldDefinition $fieldDefinition): void
    {
        $this->inner->removeFieldDefinition($contentTypeDraft, $fieldDefinition);
    }

    public function updateFieldDefinition(ContentTypeDraft $contentTypeDraft, FieldDefinition $fieldDefinition, FieldDefinitionUpdateStruct $fieldDefinitionUpdateStruct): void
    {
        $this->inner->updateFieldDefinition($contentTypeDraft, $fieldDefinition, $fieldDefinitionUpdateStruct);
    }

    public function publishContentTypeDraft(ContentTypeDraft $contentTypeDraft): void
    {
        error_log('Publishing content type draft with identifier: ' . $contentTypeDraft->identifier);
        $this->logger->info('Publishing content type draft', ['identifier' => $contentTypeDraft->identifier]);
        try {
            $this->inner->loadContentTypeByIdentifier($contentTypeDraft->identifier);
            $isUpdate = true;
        } catch (NotFoundException $e) {
            $isUpdate = false;
        }
        $this->inner->publishContentTypeDraft($contentTypeDraft);
        try {
            $result = $this->inner->loadContentTypeByIdentifier($contentTypeDraft->identifier);
            $this->logger->info('Dispatching event', ['identifier' => $contentTypeDraft->identifier, 'isUpdate' => $isUpdate]);
            if ($isUpdate) {
                $this->eventDispatcher->dispatch(new ContentTypeUpdatedEvent($result));
            } else {
                $this->eventDispatcher->dispatch(new ContentTypeCreatedEvent($result, $contentTypeDraft->identifier));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load published content type', ['identifier' => $contentTypeDraft->identifier, 'exception' => $e->getMessage()]);
            if (!$isUpdate) {
                $this->eventDispatcher->dispatch(new ContentTypeCreatedEvent(null, $contentTypeDraft->identifier));
            }
        }
    }

    public function newContentTypeGroupCreateStruct(string $identifier): ContentTypeGroupCreateStruct
    {
        return $this->inner->newContentTypeGroupCreateStruct($identifier);
    }

    public function newContentTypeCreateStruct(string $identifier): ContentTypeCreateStruct
    {
        return $this->inner->newContentTypeCreateStruct($identifier);
    }

    public function newContentTypeUpdateStruct(): ContentTypeUpdateStruct
    {
        return $this->inner->newContentTypeUpdateStruct();
    }

    public function newContentTypeGroupUpdateStruct(): ContentTypeGroupUpdateStruct
    {
        return $this->inner->newContentTypeGroupUpdateStruct();
    }

    public function newFieldDefinitionCreateStruct(string $identifier, string $fieldTypeIdentifier): FieldDefinitionCreateStruct
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

    public function removeContentTypeTranslation(ContentTypeDraft $contentTypeDraft, string $languageCode): ContentTypeDraft
    {
        return $this->inner->removeContentTypeTranslation($contentTypeDraft, $languageCode);
    }

    public function deleteUserDrafts(int $userId): void
    {
        $this->inner->deleteUserDrafts($userId);
    }
}
