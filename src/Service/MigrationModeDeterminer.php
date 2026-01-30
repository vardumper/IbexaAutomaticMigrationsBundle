<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\Service;

final class MigrationModeDeterminer
{
    /**
     * Determine whether a publish event corresponds to a create or update operation.
     * Returns 'create' when the content type identifier cannot be found in the repository,
     * otherwise 'update'. This uses repository lookup by identifier when available.
     *
     * @param object $contentTypeDraft Must have 'identifier' property or getIdentifier() method
     * @param object $contentTypeService Must have loadContentTypeByIdentifier($identifier) or loadContentType($id)
     * @return string 'create' or 'update'
     */
    public function determineCreateOrUpdateMode($contentTypeDraft, $contentTypeService): string
    {
        $identifier = null;
        if (isset($contentTypeDraft->identifier)) {
            $identifier = $contentTypeDraft->identifier;
        } elseif (method_exists($contentTypeDraft, 'getIdentifier')) {
            $identifier = $contentTypeDraft->getIdentifier();
        }

        if ($identifier === null) {
            return 'update';
        }

        // Prefer a direct identifier-based load if the service exposes it
        if (is_callable([$contentTypeService, 'loadContentTypeByIdentifier'])) {
            try {
                $found = $contentTypeService->loadContentTypeByIdentifier($identifier);
                return $found ? 'update' : 'create';
            } catch (\Throwable $e) {
                // Log or handle as needed in real usage
                return 'update';
            }
        }

        // Fallback: if the service exposes a loadContentTypeByIdentifier-like method name
        try {
            if (is_callable([$contentTypeService, 'loadContentType'])) {
                // We cannot reliably detect existence by identifier without a dedicated method,
                // so assume update when a published content type could be loaded by id.
                return 'update';
            }
        } catch (\Throwable $e) {
            // ignore and assume update
        }

        return 'update';
    }
}
