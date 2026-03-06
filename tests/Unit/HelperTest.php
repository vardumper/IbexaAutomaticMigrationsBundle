<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use vardumper\IbexaAutomaticMigrationsBundle\Helper\Helper;

describe('Helper::fixKaliopMigrationYaml', function () {
    beforeEach(function () {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'migration_test_') . '.yml';
    });

    afterEach(function () {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    });

    it('returns true for an empty file (no-op)', function () {
        file_put_contents($this->tmpFile, '');

        $result = Helper::fixKaliopMigrationYaml($this->tmpFile, new NullLogger());

        expect($result)->toBeTrue();
    });

    it('returns false for invalid YAML syntax', function () {
        file_put_contents($this->tmpFile, "invalid: yaml:\n  bad: [unclosed bracket\n    nested:\n");

        $result = Helper::fixKaliopMigrationYaml($this->tmpFile, new NullLogger());

        expect($result)->toBeFalse();
    });

    it('returns true and does not modify already-correct YAML', function () {
        $yaml = <<<'YAML'
-
    type: content_type
    mode: create
    identifier: my_type
    attributes:
        -
            identifier: title
            type: ibexa_string
            field-settings: {  }
            validator-configuration:
                StringLengthValidator: { maxStringLength: null, minStringLength: null }
YAML;
        file_put_contents($this->tmpFile, $yaml);
        $before = file_get_contents($this->tmpFile);

        $result = Helper::fixKaliopMigrationYaml($this->tmpFile, new NullLogger());

        expect($result)->toBeTrue();
        expect(file_get_contents($this->tmpFile))->toBe($before);
    });

    it('inserts field-settings before validator-configuration for ibexa_string', function () {
        $yaml = <<<'YAML'
-
    type: content_type
    mode: create
    attributes:
        -
            identifier: title
            type: ibexa_string
            validator-configuration:
                StringLengthValidator: { maxStringLength: null, minStringLength: null }
YAML;
        file_put_contents($this->tmpFile, $yaml);

        $result = Helper::fixKaliopMigrationYaml($this->tmpFile, new NullLogger());
        $content = file_get_contents($this->tmpFile);

        expect($result)->toBeTrue();
        expect($content)->toContain('            field-settings: {  }');
        // field-settings must appear before validator-configuration
        expect(strpos($content, 'field-settings:'))->toBeLessThan(strpos($content, 'validator-configuration:'));
    });

    it('inserts field-settings for ibexa_integer missing it', function () {
        $yaml = <<<'YAML'
-
    type: content_type
    mode: create
    attributes:
        -
            identifier: count
            type: ibexa_integer
            validator-configuration:
                IntegerValueValidator: { minIntegerValue: null, maxIntegerValue: null }
YAML;
        file_put_contents($this->tmpFile, $yaml);

        Helper::fixKaliopMigrationYaml($this->tmpFile, new NullLogger());
        $content = file_get_contents($this->tmpFile);

        expect($content)->toContain('            field-settings: {  }');
    });

    it('inserts both field-settings and validator-configuration for ibexa_boolean missing both', function () {
        $yaml = <<<'YAML'
-
    type: content_type
    mode: create
    attributes:
        -
            identifier: active
            type: ibexa_boolean
YAML;
        file_put_contents($this->tmpFile, $yaml);

        Helper::fixKaliopMigrationYaml($this->tmpFile, new NullLogger());
        $content = file_get_contents($this->tmpFile);

        expect($content)->toContain('            field-settings: {  }');
        expect($content)->toContain('            validator-configuration: {  }');
    });

    it('inserts only field-settings when ibexa_boolean already has validator-configuration', function () {
        $yaml = <<<'YAML'
-
    type: content_type
    mode: create
    attributes:
        -
            identifier: active
            type: ibexa_boolean
            validator-configuration: {  }
YAML;
        file_put_contents($this->tmpFile, $yaml);

        Helper::fixKaliopMigrationYaml($this->tmpFile, new NullLogger());
        $content = file_get_contents($this->tmpFile);

        expect($content)->toContain('            field-settings: {  }');
        expect(substr_count($content, 'validator-configuration:'))->toBe(1);
    });

    it('does not duplicate field-settings when already present', function () {
        $yaml = <<<'YAML'
-
    type: content_type
    mode: create
    attributes:
        -
            identifier: title
            type: ibexa_string
            field-settings: {  }
            validator-configuration:
                StringLengthValidator: { maxStringLength: null, minStringLength: null }
YAML;
        file_put_contents($this->tmpFile, $yaml);

        Helper::fixKaliopMigrationYaml($this->tmpFile, new NullLogger());
        $content = file_get_contents($this->tmpFile);

        expect(substr_count($content, 'field-settings:'))->toBe(1);
    });

    it('handles multiple attributes in one block correctly', function () {
        $yaml = <<<'YAML'
-
    type: content_type
    mode: create
    attributes:
        -
            identifier: title
            type: ibexa_string
            validator-configuration:
                StringLengthValidator: { maxStringLength: null, minStringLength: null }
        -
            identifier: count
            type: ibexa_integer
            validator-configuration:
                IntegerValueValidator: { minIntegerValue: null, maxIntegerValue: null }
YAML;
        file_put_contents($this->tmpFile, $yaml);

        Helper::fixKaliopMigrationYaml($this->tmpFile, new NullLogger());
        $content = file_get_contents($this->tmpFile);

        expect(substr_count($content, 'field-settings:'))->toBe(2);
    });

    it('does not add field-settings to non-string/integer/boolean attribute types', function () {
        $yaml = <<<'YAML'
-
    type: content_type
    mode: create
    attributes:
        -
            identifier: body
            type: ibexa_richtext
YAML;
        file_put_contents($this->tmpFile, $yaml);
        $before = file_get_contents($this->tmpFile);

        Helper::fixKaliopMigrationYaml($this->tmpFile, new NullLogger());

        expect(file_get_contents($this->tmpFile))->toBe($before);
    });

    it('injects match_tolerate_misses into a delete step', function () {
        $yaml = <<<'YAML'
-
    type: content_type
    mode: delete
    match:
        content_type_identifier: old_type
YAML;
        file_put_contents($this->tmpFile, $yaml);

        Helper::fixKaliopMigrationYaml($this->tmpFile, new NullLogger());
        $content = file_get_contents($this->tmpFile);

        expect($content)->toContain('    match_tolerate_misses: true');
    });

    it('does not add match_tolerate_misses to create steps', function () {
        $yaml = <<<'YAML'
-
    type: content_type
    mode: create
    identifier: my_type
YAML;
        file_put_contents($this->tmpFile, $yaml);

        Helper::fixKaliopMigrationYaml($this->tmpFile, new NullLogger());
        $content = file_get_contents($this->tmpFile);

        expect($content)->not->toContain('match_tolerate_misses');
    });

    it('does not add match_tolerate_misses to update steps', function () {
        $yaml = <<<'YAML'
-
    type: content_type
    mode: update
    match:
        content_type_identifier: my_type
YAML;
        file_put_contents($this->tmpFile, $yaml);

        Helper::fixKaliopMigrationYaml($this->tmpFile, new NullLogger());
        $content = file_get_contents($this->tmpFile);

        expect($content)->not->toContain('match_tolerate_misses');
    });

    it('does not duplicate match_tolerate_misses when already present on delete step', function () {
        $yaml = <<<'YAML'
-
    type: content_type
    mode: delete
    match_tolerate_misses: true
    match:
        content_type_identifier: old_type
YAML;
        file_put_contents($this->tmpFile, $yaml);
        $before = file_get_contents($this->tmpFile);

        Helper::fixKaliopMigrationYaml($this->tmpFile, new NullLogger());

        $content = file_get_contents($this->tmpFile);
        expect(substr_count($content, 'match_tolerate_misses:'))->toBe(1);
        expect($content)->toBe($before);
    });

    it('handles multiple steps: injects only into delete steps', function () {
        $yaml = <<<'YAML'
-
    type: content_type
    mode: create
    identifier: new_type
    attributes:
        -
            identifier: title
            type: ibexa_string
            field-settings: {  }
            validator-configuration:
                StringLengthValidator: { maxStringLength: null, minStringLength: null }
-
    type: content_type
    mode: delete
    match:
        content_type_identifier: old_type
YAML;
        file_put_contents($this->tmpFile, $yaml);

        Helper::fixKaliopMigrationYaml($this->tmpFile, new NullLogger());
        $content = file_get_contents($this->tmpFile);

        expect(substr_count($content, 'match_tolerate_misses:'))->toBe(1);
        expect($content)->toContain('    match_tolerate_misses: true');
    });
});
