<?php

declare(strict_types=1);

use vardumper\IbexaAutomaticMigrationsBundle\Service\SettingsService;

describe('SettingsService', function () {
    beforeEach(function () {
        $this->tmpDir = sys_get_temp_dir() . '/settings_service_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        $this->settingsFile = $this->tmpDir . '/var/ibexa_automatic_migrations_settings.yaml';
    });

    afterEach(function () {
        if (file_exists($this->settingsFile)) {
            unlink($this->settingsFile);
        }
        $varDir = dirname($this->settingsFile);
        if (is_dir($varDir)) {
            rmdir($varDir);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    });

    it('getSettings returns defaults when settings file does not exist', function () {
        $service = new SettingsService($this->tmpDir, true, ['content_type' => true, 'section' => false]);

        $settings = $service->getSettings();

        expect($settings['enabled'])->toBeTrue();
        expect($settings['types'])->toBe(['content_type' => true, 'section' => false]);
    });

    it('getSettings reads enabled flag from file', function () {
        mkdir(dirname($this->settingsFile), 0777, true);
        file_put_contents($this->settingsFile, "enabled: false\ntypes: []\n");

        $service = new SettingsService($this->tmpDir, true, ['content_type' => true]);
        $settings = $service->getSettings();

        expect($settings['enabled'])->toBeFalse();
    });

    it('getSettings falls back to default enabled when file is missing the key', function () {
        mkdir(dirname($this->settingsFile), 0777, true);
        file_put_contents($this->settingsFile, "types: []\n");

        $service = new SettingsService($this->tmpDir, true, []);
        $settings = $service->getSettings();

        expect($settings['enabled'])->toBeTrue();
    });

    it('getSettings merges file types over default types', function () {
        mkdir(dirname($this->settingsFile), 0777, true);
        file_put_contents($this->settingsFile, "enabled: true\ntypes:\n  section: true\n");

        $service = new SettingsService($this->tmpDir, true, ['content_type' => true, 'section' => false]);
        $settings = $service->getSettings();

        expect($settings['types']['section'])->toBeTrue();   // file overrides default
        expect($settings['types']['content_type'])->toBeTrue(); // default preserved
    });

    it('saveSettings creates the settings file with YAML content', function () {
        $service = new SettingsService($this->tmpDir, true, []);
        $service->saveSettings(['enabled' => false, 'types' => ['content_type' => false]]);

        expect(file_exists($this->settingsFile))->toBeTrue();
        $content = file_get_contents($this->settingsFile);
        expect($content)->toContain('enabled: false');
        expect($content)->toContain('content_type: false');
    });

    it('saveSettings overwrites an existing settings file', function () {
        mkdir(dirname($this->settingsFile), 0777, true);
        file_put_contents($this->settingsFile, "enabled: true\ntypes: []\n");

        $service = new SettingsService($this->tmpDir, true, []);
        $service->saveSettings(['enabled' => false, 'types' => []]);

        $content = file_get_contents($this->settingsFile);
        expect($content)->toContain('enabled: false');
        expect($content)->not->toContain('enabled: true');
    });

    it('isEnabled returns false when APP_ENV is not dev', function () {
        // phpunit.xml sets APP_ENV=testing, so this should be false
        $service = new SettingsService($this->tmpDir, true, []);

        expect($service->isEnabled())->toBeFalse();
    });

    it('isEnabled returns true when APP_ENV is dev and settings say enabled', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';

        try {
            $service = new SettingsService($this->tmpDir, true, []);
            expect($service->isEnabled())->toBeTrue();
        } finally {
            if ($previous === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $previous;
            }
        }
    });

    it('isEnabled returns false in dev environment when settings disabled', function () {
        $previous = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = 'dev';

        try {
            $service = new SettingsService($this->tmpDir, false, []);
            expect($service->isEnabled())->toBeFalse();
        } finally {
            if ($previous === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $previous;
            }
        }
    });

    it('isTypeEnabled returns true for an enabled type in defaults', function () {
        $service = new SettingsService($this->tmpDir, true, ['content_type' => true, 'section' => false]);

        expect($service->isTypeEnabled('content_type'))->toBeTrue();
        expect($service->isTypeEnabled('section'))->toBeFalse();
    });

    it('isTypeEnabled returns false for a type not present in settings', function () {
        $service = new SettingsService($this->tmpDir, true, []);

        expect($service->isTypeEnabled('nonexistent_type'))->toBeFalse();
    });

    it('isTypeEnabled reads from file when settings file exists', function () {
        mkdir(dirname($this->settingsFile), 0777, true);
        file_put_contents($this->settingsFile, "enabled: true\ntypes:\n  content_type: false\n");

        $service = new SettingsService($this->tmpDir, true, ['content_type' => true]);

        expect($service->isTypeEnabled('content_type'))->toBeFalse(); // file overrides default
    });
});
