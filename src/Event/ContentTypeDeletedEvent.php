<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Ibexa\Core\Repository\Values\ContentType\ContentTypeDraft;
use Ibexa\Core\Repository\Values\ContentType\ContentType;

class ContentTypeDeletedEvent extends Event
{
    public function __construct(public readonly ContentType|ContentTypeDraft $contentType) {}
}
