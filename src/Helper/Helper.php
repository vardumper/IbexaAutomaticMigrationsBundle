<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\Helper;

use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class Helper
{
    private const DESTINATION_KALIOP = 'src/MigrationsDefinitions';
    private const DESTINATION_IBEXA = 'src/Migrations/Ibexa/migrations';

    public static function determineMode(): ?string
    {
        // Force autoloader to load the classes
        if (class_exists('Ibexa\\Bundle\\Migration\\Command\\GenerateCommand', true)) {
            return 'ibexa';
        }
        if (class_exists('Kaliop\\IbexaMigrationBundle\\Command\\GenerateCommand', true)) {
            return 'kaliop';
        }
        return null;
    }

    public static function determineDestination(string $projectDir): ?string
    {
        $mode = self::determineMode();
        if ($mode === 'ibexa') {
            return $projectDir . DIRECTORY_SEPARATOR . self::DESTINATION_IBEXA;
        }
        if ($mode === 'kaliop') {
            return $projectDir . DIRECTORY_SEPARATOR . self::DESTINATION_KALIOP;
        }
        return null;
    }

    /**
     * Validates YAML syntax, ensures all ibexa_string / ibexa_integer / ibexa_boolean
     * attribute blocks carry field-settings and validator-configuration, and injects
     * match_tolerate_misses: true into every delete step.
     *
     * Returns true on success, false when a YAML parse error is detected.
     */
    public static function fixKaliopMigrationYaml(string $fullPath, LoggerInterface $logger): bool
    {
        $content = file_get_contents($fullPath);
        if ($content === false) {
            return true;
        }

        // Quick syntax check
        try {
            Yaml::parse($content);
        } catch (ParseException $e) {
            $logger->error('Generated migration has invalid YAML syntax', [
                'file' => $fullPath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        $typesNeedingFieldSettings = ['ibexa_string', 'ibexa_integer', 'ibexa_boolean'];
        $attrIndent = '            '; // 12 spaces — attribute key indent in Kaliop YAML
        $attrMarker = '/^        -\s*$/'; // 8-space hyphen marks start of an attribute block

        $lines = explode("\n", $content);
        $output = [];
        $total = count($lines);
        $i = 0;

        while ($i < $total) {
            $line = $lines[$i];

            if (!preg_match($attrMarker, $line)) {
                $output[] = $line;
                $i++;
                continue;
            }

            // Collect the entire attribute block (including sub-keys at 16-space indent)
            $blockLines = [$line];
            $i++;
            while ($i < $total
                && !preg_match($attrMarker, $lines[$i])
                && ($lines[$i] === '' || str_starts_with($lines[$i], '        '))
            ) {
                $blockLines[] = $lines[$i];
                $i++;
            }

            // Determine field type from within the block
            $type = null;
            foreach ($blockLines as $bLine) {
                if (preg_match('/^            type:\s+(\S+)/', $bLine, $m)) {
                    $type = $m[1];
                    break;
                }
            }

            if ($type !== null && in_array($type, $typesNeedingFieldSettings, true)) {
                $hasFieldSettings = false;
                $hasValidatorConf = false;
                foreach ($blockLines as $bLine) {
                    if (str_starts_with($bLine, $attrIndent . 'field-settings:')) {
                        $hasFieldSettings = true;
                    }
                    if (str_starts_with($bLine, $attrIndent . 'validator-configuration:')) {
                        $hasValidatorConf = true;
                    }
                }

                $needsFieldSettings = !$hasFieldSettings;
                $needsValidatorConf = $type === 'ibexa_boolean' && !$hasValidatorConf;

                if ($needsFieldSettings || $needsValidatorConf) {
                    $newBlock = [];
                    $fieldSettingsAdded = !$needsFieldSettings;

                    foreach ($blockLines as $bLine) {
                        // Insert field-settings immediately before validator-configuration
                        if (!$fieldSettingsAdded && str_starts_with($bLine, $attrIndent . 'validator-configuration:')) {
                            $newBlock[] = $attrIndent . 'field-settings: {  }';
                            $fieldSettingsAdded = true;
                        }
                        $newBlock[] = $bLine;
                    }

                    // field-settings still missing (no validator-configuration existed in block)
                    if (!$fieldSettingsAdded) {
                        $insertAt = count($newBlock);
                        while ($insertAt > 0 && trim($newBlock[$insertAt - 1]) === '') {
                            $insertAt--;
                        }
                        array_splice($newBlock, $insertAt, 0, [$attrIndent . 'field-settings: {  }']);
                    }

                    // validator-configuration missing for ibexa_boolean
                    if ($needsValidatorConf) {
                        $insertAt = count($newBlock);
                        while ($insertAt > 0 && trim($newBlock[$insertAt - 1]) === '') {
                            $insertAt--;
                        }
                        array_splice($newBlock, $insertAt, 0, [$attrIndent . 'validator-configuration: {  }']);
                    }

                    $blockLines = $newBlock;
                }
            }

            foreach ($blockLines as $bLine) {
                $output[] = $bLine;
            }
        }

        $newContent = implode("\n", $output);

        // Pass 2: inject match_tolerate_misses: true into every top-level delete step
        $stepMarker = '/^-\s*$/'; // 0-space hyphen = start of a top-level step
        $lines2 = explode("\n", $newContent);
        $output2 = [];
        $total2 = count($lines2);
        $j = 0;

        while ($j < $total2) {
            $line = $lines2[$j];

            if (!preg_match($stepMarker, $line)) {
                $output2[] = $line;
                $j++;
                continue;
            }

            // Collect entire top-level step (all lines until the next 0-space hyphen)
            $stepBlock = [$line];
            $j++;
            while ($j < $total2 && !preg_match($stepMarker, $lines2[$j])) {
                $stepBlock[] = $lines2[$j];
                $j++;
            }

            $isDelete = false;
            $hasTolerateMisses = false;
            foreach ($stepBlock as $sLine) {
                if (preg_match('/^    mode:\s+delete\s*$/', $sLine)) {
                    $isDelete = true;
                }
                if (str_starts_with($sLine, '    match_tolerate_misses:')) {
                    $hasTolerateMisses = true;
                }
            }

            if ($isDelete && !$hasTolerateMisses) {
                $newStep = [];
                $inMatchBlock = false;
                $inserted = false;

                foreach ($stepBlock as $sLine) {
                    if (!$inserted) {
                        if (preg_match('/^    match:\s*$/', $sLine)) {
                            $inMatchBlock = true;
                        } elseif ($inMatchBlock && trim($sLine) !== '' && !str_starts_with($sLine, '        ')) {
                            $newStep[] = '    match_tolerate_misses: true';
                            $inserted = true;
                            $inMatchBlock = false;
                        }
                    }
                    $newStep[] = $sLine;
                }

                // match: was the last key in the step
                if (!$inserted) {
                    $insertAt = count($newStep);
                    while ($insertAt > 0 && trim($newStep[$insertAt - 1]) === '') {
                        $insertAt--;
                    }
                    array_splice($newStep, $insertAt, 0, ['    match_tolerate_misses: true']);
                }

                $stepBlock = $newStep;
            }

            foreach ($stepBlock as $sLine) {
                $output2[] = $sLine;
            }
        }

        $finalContent = implode("\n", $output2);

        if ($finalContent !== $content) {
            file_put_contents($fullPath, $finalContent);
            $logger->info('Fixed migration YAML: added missing field-settings/validator-configuration/match_tolerate_misses', [
                'file' => $fullPath,
            ]);
        }

        return true;
    }
}
