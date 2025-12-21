<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\Event;

use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroup;
use Symfony\Contracts\EventDispatcher\Event;

class ContentTypeGroupCreatedEvent extends Event
{
    public function __construct(public readonly ContentTypeGroup $contentTypeGroup) {}
}
