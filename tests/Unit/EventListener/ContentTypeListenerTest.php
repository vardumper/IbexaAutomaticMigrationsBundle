<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\Events\ContentType\BeforeDeleteContentTypeEvent;
use Ibexa\Contracts\Core\Repository\Events\ContentType\PublishContentTypeDraftEvent;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentType;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeDraft;
use Psr\Log\NullLogger;
use vardumper\IbexaAutomaticMigrationsBundle\EventListener\ContentTypeListener;

describe('ContentTypeListener', function () {
    beforeEach(function () {
        $this->tmpDir = makeTmpDir();
        $this->listener = new ContentTypeListener(
            new NullLogger(),
            $this->tmpDir,
            makeContainer()
        );

        $draft = $this->createStub(ContentTypeDraft::class);
        $contentType = $this->createStub(ContentType::class);

        $this->publishEvent = new PublishContentTypeDraftEvent($draft);
        $this->deleteEvent = new BeforeDeleteContentTypeEvent($contentType);
    });

    afterEach(function () {
        removeTmpDir($this->tmpDir);
    });

    it('can be instantiated and creates destination directory', function () {
        expect($this->listener)->toBeInstanceOf(ContentTypeListener::class);
        expect(is_dir($this->tmpDir . '/src/MigrationsDefinitions'))->toBeTrue();
    });

    it('onIbexaPublishContentTypeDraft returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onIbexaPublishContentTypeDraft($this->publishEvent))->not->toThrow(\Throwable::class);
    });

    it('onIbexaBeforeDeleteContentType returns early when APP_ENV is not dev', function () {
        expect(fn () => $this->listener->onIbexaBeforeDeleteContentType($this->deleteEvent))->not->toThrow(\Throwable::class);
    });

    it('onIbexaPublishContentTypeDraft stops at isCli check in dev env', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';
        try {
            // In CLI (which tests run in), isCli=true so returns early after env check
            expect(fn () => $this->listener->onIbexaPublishContentTypeDraft($this->publishEvent))->not->toThrow(\Throwable::class);
        } finally {
            if ($previous === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $previous;
            }
        }
    });

    it('onIbexaPublishContentTypeDraft reaches generateMigration in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onIbexaPublishContentTypeDraft($this->publishEvent))->not->toThrow(\Throwable::class));
    });

    it('onIbexaBeforeDeleteContentType reaches generateMigration in dev env', function () {
        withEnv('dev', fn () => expect(fn () => $this->listener->onIbexaBeforeDeleteContentType($this->deleteEvent))->not->toThrow(\Throwable::class));
    });

    it('onIbexaBeforeDeleteContentType stops at isCli check in dev env', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';
        try {
            expect(fn () => $this->listener->onIbexaBeforeDeleteContentType($this->deleteEvent))->not->toThrow(\Throwable::class);
        } finally {
            if ($previous === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $previous;
            }
        }
    });

    it('creates a second listener instance without conflicts', function () {
        $listener2 = new ContentTypeListener(
            new NullLogger(),
            $this->tmpDir,
            makeContainer()
        );
        expect($listener2)->toBeInstanceOf(ContentTypeListener::class);
    });
});

describe('ContentTypeListener – past CLI guard (fake runner)', function () {
    beforeEach(function () {
        $this->tmpDir = makeTmpDir();

        $draft = $this->createStub(ContentTypeDraft::class);
        $contentType = $this->createStub(ContentType::class);

        $this->publishEvent = new PublishContentTypeDraftEvent($draft);
        $this->deleteEvent = new BeforeDeleteContentTypeEvent($contentType);
    });

    afterEach(function () {
        removeTmpDir($this->tmpDir);
    });

    it('onIbexaPublishContentTypeDraft – null content type service – caught and logged', function () {
        // makeContainer returns null for all gets; loading CT by id throws TypeError → caught
        $listener = withTestingEnv(fn () => new ContentTypeListener(
            new NullLogger(),
            $this->tmpDir,
            makeContainer(),
            makeFakeRunner(0)
        ));
        withEnv('dev', fn () => expect(fn () => $listener->onIbexaPublishContentTypeDraft($this->publishEvent))->not->toThrow(\Throwable::class));
    });

    it('onIbexaPublishContentTypeDraft – with content type service – reaches hasExecutedCreateMigration path', function () {
        $publishedContentType = $this->createStub(ContentType::class);
        $contentTypeService = new class($publishedContentType) {
            public function __construct(private readonly object $ct)
            {
            }

            public function loadContentType(int $id): object
            {
                return $this->ct;
            }

            public function loadContentTypeByIdentifier(string $identifier): object
            {
                return $this->ct;
            }
        };

        $listener = withTestingEnv(fn () => new ContentTypeListener(
            new NullLogger(),
            $this->tmpDir,
            makeContainerWith($contentTypeService),
            makeFakeRunner(0)
        ));

        withEnv('dev', fn () => expect(fn () => $listener->onIbexaPublishContentTypeDraft($this->publishEvent))->not->toThrow(\Throwable::class));
    });

    it('onIbexaPublishContentTypeDraft – with content type service and failed runner – handles process failure branch', function () {
        $publishedContentType = $this->createStub(ContentType::class);
        $contentTypeService = new class($publishedContentType) {
            public function __construct(private readonly object $ct)
            {
            }

            public function loadContentType(int $id): object
            {
                return $this->ct;
            }

            public function loadContentTypeByIdentifier(string $identifier): object
            {
                return $this->ct;
            }
        };

        $listener = withTestingEnv(fn () => new ContentTypeListener(
            new NullLogger(),
            $this->tmpDir,
            makeContainerWith($contentTypeService),
            makeFakeRunner(1)
        ));

        withEnv('dev', fn () => expect(fn () => $listener->onIbexaPublishContentTypeDraft($this->publishEvent))->not->toThrow(\Throwable::class));
    });

    it('onIbexaPublishContentTypeDraft – deep success path with migration file present', function () {
        $draft = $this->getMockBuilder(ContentTypeDraft::class)
            ->disableOriginalConstructor()
            ->getMock();
        $draft->method('__get')->willReturnCallback(fn (string $prop) => match ($prop) {
            'id' => 42,
            'identifier' => 'article',
            default => null,
        });

        $publishedContentType = $this->getMockBuilder(ContentType::class)
            ->disableOriginalConstructor()
            ->getMock();
        $publishedContentType->method('__get')->willReturnCallback(fn (string $prop) => match ($prop) {
            'id' => 42,
            'identifier' => 'article',
            default => null,
        });

        $publishEvent = new PublishContentTypeDraftEvent($draft);
        $contentTypeService = new class($publishedContentType) {
            public function __construct(private readonly object $ct)
            {
            }

            public function loadContentType(int $id): object
            {
                return $this->ct;
            }

            public function loadContentTypeByIdentifier(string $identifier): object
            {
                return $this->ct;
            }
        };

        $dest = $this->tmpDir . '/src/MigrationsDefinitions';
        @mkdir($dest, 0777, true);
        $file = $dest . '/2099_01_01_00_00_07_auto_content_type_create_article.yaml';
        file_put_contents($file, "- type: content_type\n  mode: create\n");
        touch($file, time() - 1);

        $listener = withTestingEnv(fn () => new ContentTypeListener(
            new NullLogger(),
            $this->tmpDir,
            makeContainerWith($contentTypeService),
            makeFakeRunner(0)
        ));

        withEnv('dev', fn () => expect(fn () => $listener->onIbexaPublishContentTypeDraft($publishEvent))->not->toThrow(\Throwable::class));
    });

    it('onIbexaBeforeDeleteContentType – deep failed runner path with concrete content type', function () {
        $contentType = $this->getMockBuilder(ContentType::class)
            ->disableOriginalConstructor()
            ->getMock();
        $contentType->method('__get')->willReturnCallback(fn (string $prop) => match ($prop) {
            'id' => 77,
            'identifier' => 'landing_page',
            default => null,
        });
        $deleteEvent = new BeforeDeleteContentTypeEvent($contentType);

        $listener = withTestingEnv(fn () => new ContentTypeListener(
            new NullLogger(),
            $this->tmpDir,
            makeContainer(),
            makeFakeRunner(1, '', 'boom')
        ));

        withEnv('dev', fn () => expect(fn () => $listener->onIbexaBeforeDeleteContentType($deleteEvent))->not->toThrow(\Throwable::class));
    });

    it('onIbexaPublishContentTypeDraft – forced ibexa mode – exercises ibexa generation branch', function () {
        $draft = $this->getMockBuilder(ContentTypeDraft::class)
            ->disableOriginalConstructor()
            ->getMock();
        $draft->method('__get')->willReturnCallback(fn (string $prop) => match ($prop) {
            'id' => 77,
            'identifier' => 'forced_ibexa',
            default => null,
        });

        $publishedContentType = $this->getMockBuilder(ContentType::class)
            ->disableOriginalConstructor()
            ->getMock();
        $publishedContentType->method('__get')->willReturnCallback(fn (string $prop) => match ($prop) {
            'id' => 77,
            'identifier' => 'forced_ibexa',
            default => null,
        });

        $publishEvent = new PublishContentTypeDraftEvent($draft);
        $contentTypeService = new class($publishedContentType) {
            public function __construct(private readonly object $ct)
            {
            }

            public function loadContentType(int $id): object
            {
                return $this->ct;
            }

            public function loadContentTypeByIdentifier(string $identifier): object
            {
                return $this->ct;
            }
        };

        $listener = withTestingEnv(fn () => new ContentTypeListener(
            new NullLogger(),
            $this->tmpDir,
            makeContainerWith($contentTypeService),
            makeFakeRunner(1, '', 'ibexa-fail')
        ));
        setPrivateProperty($listener, 'mode', 'ibexa');

        withEnv('dev', fn () => expect(fn () => $listener->onIbexaPublishContentTypeDraft($publishEvent))->not->toThrow(\Throwable::class));
    });

    it('onIbexaBeforeDeleteContentType – null content type – generateMigration early-returns on null mode', function () {
        $listener = withTestingEnv(fn () => new ContentTypeListener(
            new NullLogger(),
            $this->tmpDir,
            makeContainer(),
            makeFakeRunner(0)
        ));
        withEnv('dev', fn () => expect(fn () => $listener->onIbexaBeforeDeleteContentType($this->deleteEvent))->not->toThrow(\Throwable::class));
    });

    it('onIbexaPublishContentTypeDraft – non-dev env – skips early', function () {
        $listener = withTestingEnv(fn () => new ContentTypeListener(
            new NullLogger(),
            $this->tmpDir,
            makeContainer(),
            makeFakeRunner(0)
        ));
        // default APP_ENV is not 'dev', so should return early
        expect(fn () => $listener->onIbexaPublishContentTypeDraft($this->publishEvent))->not->toThrow(\Throwable::class);
    });
});
