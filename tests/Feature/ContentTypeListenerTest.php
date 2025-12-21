<?php

use Ibexa\Contracts\Core\Repository\Events\ContentType\BeforeDeleteContentTypeEvent;
use Ibexa\Contracts\Core\Repository\Events\ContentType\PublishContentTypeDraftEvent;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentType;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeDraft;
use vardumper\IbexaAutomaticMigrationsBundle\EventListener\ContentTypeListener;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

test('content type listener handles before delete event', function () {
    // Mock dependencies
    $logger = $this->createMock(LoggerInterface::class);
    $container = $this->createMock(ContainerInterface::class);

    // Set up logger expectations
    $logger->expects($this->once())->method('info')
        ->with('IbexaAutomaticMigrationsBundle: BeforeDeleteContentTypeEvent received', ['event' => BeforeDeleteContentTypeEvent::class]);
    $logger->expects($this->once())->method('info')
        ->with('BeforeDeleteContentTypeEvent received', ['id' => 123, 'identifier' => 'test_content_type']);

    // Create listener
    $listener = new ContentTypeListener($logger, '/tmp', $container);

    // Mock content type
    $contentType = $this->createMock(ContentType::class);
    $contentType->method('getId')->willReturn(123);
    $contentType->method('getIdentifier')->willReturn('test_content_type');

    // Mock event
    $event = $this->createMock(BeforeDeleteContentTypeEvent::class);
    $event->method('getContentType')->willReturn($contentType);

    // Mock container to return database connection
    $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
    $connection->expects($this->once())->method('insert');
    $container->method('get')
        ->with('doctrine.dbal.default_connection')
        ->willReturn($connection);

    // Set CLI to false to allow processing
    $reflection = new \ReflectionClass($listener);
    $cliProperty = $reflection->getProperty('isCli');
    $cliProperty->setAccessible(true);
    $cliProperty->setValue($listener, false);

    // Call the event handler
    $listener->onIbexaBeforeDeleteContentType($event);
});

test('content type listener skips processing in CLI mode', function () {
    // Mock dependencies
    $logger = $this->createMock(LoggerInterface::class);
    $container = $this->createMock(ContainerInterface::class);

    // Set up logger expectation
    $logger->expects($this->once())->method('info')
        ->with('IbexaAutomaticMigrationsBundle: Skipping because running in CLI');

    // Create listener
    $listener = new ContentTypeListener($logger, '/tmp', $container);

    // Mock content type
    $contentType = $this->createMock(ContentType::class);

    // Mock event
    $event = $this->createMock(BeforeDeleteContentTypeEvent::class);
    $event->method('getContentType')->willReturn($contentType);

    // Set CLI to true to skip processing
    $reflection = new \ReflectionClass($listener);
    $cliProperty = $reflection->getProperty('isCli');
    $cliProperty->setAccessible(true);
    $cliProperty->setValue($listener, true);

    // Call the event handler
    $listener->onIbexaBeforeDeleteContentType($event);
});

test('content type listener handles publish content type draft event', function () {
    // Mock dependencies
    $logger = $this->createMock(LoggerInterface::class);
    $container = $this->createMock(ContainerInterface::class);

    // Set up logger expectation
    $logger->expects($this->once())->method('info')
        ->with('IbexaAutomaticMigrationsBundle: PublishContentTypeDraftEvent received', ['event' => PublishContentTypeDraftEvent::class]);

    // Create listener
    $listener = new ContentTypeListener($logger, '/tmp', $container);

    // Mock content type draft
    $contentTypeDraft = $this->createMock(ContentTypeDraft::class);
    $contentTypeDraft->method('getId')->willReturn(456);
    $contentTypeDraft->method('getIdentifier')->willReturn('test_draft');

    // Mock published content type
    $publishedContentType = $this->createMock(ContentType::class);
    $publishedContentType->method('getId')->willReturn(456);
    $publishedContentType->method('getIdentifier')->willReturn('test_content_type');

    // Mock content type service
    $contentTypeService = $this->createMock(\Ibexa\Contracts\Core\Repository\ContentTypeService::class);
    $contentTypeService->method('loadContentType')->with(456)->willReturn($publishedContentType);

    // Mock event
    $event = $this->createMock(PublishContentTypeDraftEvent::class);
    $event->method('getContentTypeDraft')->willReturn($contentTypeDraft);

    // Mock container
    $container->method('get')
        ->with('ibexa.api.service.content_type')
        ->willReturn($contentTypeService);

    // Set CLI to false
    $reflection = new \ReflectionClass($listener);
    $cliProperty = $reflection->getProperty('isCli');
    $cliProperty->setAccessible(true);
    $cliProperty->setValue($listener, false);

    // Call the event handler
    $listener->onIbexaPublishContentTypeDraft($event);
});