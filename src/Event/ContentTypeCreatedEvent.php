<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentType;

class ContentTypeCreatedEvent extends Event
{
    public function __construct(public readonly ?ContentType $contentType, public readonly string $identifier) {}
}
