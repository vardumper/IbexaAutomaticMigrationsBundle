<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(PHPUnit\Framework\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

expect()->extend('toBeTwo', function () {
    return $this->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the amount of code duplication.
|
*/

function something()
{
    // ..
}

/**
 * Create a minimal ContainerInterface stub for use in EventListener tests.
 */
function makeContainer(): \Symfony\Component\DependencyInjection\ContainerInterface
{
    return new class() implements \Symfony\Component\DependencyInjection\ContainerInterface {
        public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
        {
            return null;
        }
        public function has(string $id): bool
        {
            return false;
        }
        public function initialized(string $id): bool
        {
            return false;
        }
        public function getParameter(string $name): array|bool|string|int|float|null
        {
            return null;
        }
        public function hasParameter(string $name): bool
        {
            return false;
        }
        public function setParameter(string $name, mixed $value): void
        {
        }
        public function set(string $id, ?object $service): void
        {
        }
    };
}

/**
 * Create a real SettingsService instance backed by a temp directory.
 */
function makeSettingsService(string $tmpDir, bool $enabled = false, array $types = []): \vardumper\IbexaAutomaticMigrationsBundle\Service\SettingsService
{
    return new \vardumper\IbexaAutomaticMigrationsBundle\Service\SettingsService($tmpDir, $enabled, $types);
}

/**
 * Create a temp directory for tests and return its path. Caller is responsible for cleanup.
 */
function makeTmpDir(): string
{
    $dir = sys_get_temp_dir() . '/ibm_test_' . uniqid();
    mkdir($dir, 0777, true);
    return $dir;
}

/**
 * Recursively remove a directory.
 */
function removeTmpDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $iter = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $item) {
        $item->isDir() ? rmdir((string)$item) : unlink((string)$item);
    }
    rmdir($dir);
}
