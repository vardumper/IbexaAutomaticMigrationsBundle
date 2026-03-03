<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class SettingsService
{
    private string $settingsFile;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        private readonly bool $defaultEnabled,
        private readonly array $defaultTypes,
    ) {
        $this->settingsFile = $projectDir . '/var/ibexa_automatic_migrations_settings.yaml';
    }

    public function getSettings(): array
    {
        if (!file_exists($this->settingsFile)) {
            return [
                'enabled' => $this->defaultEnabled,
                'types' => $this->defaultTypes,
            ];
        }

        $settings = Yaml::parseFile($this->settingsFile);

        return [
            'enabled' => $settings['enabled'] ?? $this->defaultEnabled,
            'types' => array_merge($this->defaultTypes, $settings['types'] ?? []),
        ];
    }

    public function saveSettings(array $settings): void
    {
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->settingsFile, Yaml::dump($settings));
    }

    public function isEnabled(): bool
    {
        // Only enable automatic migrations in dev environment
        $env = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null;
        if ($env !== 'dev') {
            return false;
        }
        return $this->getSettings()['enabled'];
    }

    public function isTypeEnabled(string $type): bool
    {
        $types = $this->getSettings()['types'];

        return $types[$type] ?? false;
    }
}
