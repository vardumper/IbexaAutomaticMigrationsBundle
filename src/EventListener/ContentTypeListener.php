<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\EventListener;

use Ibexa\Contracts\Core\Repository\Events\ContentType\BeforeDeleteContentTypeEvent;
use Ibexa\Contracts\Core\Repository\Events\ContentType\PublishContentTypeDraftEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use vardumper\IbexaAutomaticMigrationsBundle\Helper\Helper;

final class ContentTypeListener
{
    private bool $isCli = false;
    private ?string $mode = null;
    private string $projectDir;
    private string $destination;
    private ContainerInterface $container;
    private array $consoleCommand;

    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        #[Autowire(service: 'service_container')]
        ContainerInterface $container
    ) {
        $this->container = $container;
        $this->projectDir = rtrim($projectDir, DIRECTORY_SEPARATOR);
        $this->isCli = PHP_SAPI === 'cli';
        $this->consoleCommand = ['php', '-d', 'memory_limit=512M', $this->projectDir . '/bin/console'];
        $this->mode = Helper::determineMode();
        $this->destination = Helper::determineDestination($this->projectDir);
        if (!is_dir($this->destination)) {
            mkdir($this->destination, 0777, true);
        }
    }

    #[AsEventListener(PublishContentTypeDraftEvent::class)]
    public function onIbexaPublishContentTypeDraft(PublishContentTypeDraftEvent $event): void
    {
        $env = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null;
        if ($env !== 'dev') {
            $this->logger->info('IbexaAutomaticMigrationsBundle: Skipping migration generation, not in dev environment');
            return;
        }
        $this->logger->info('IbexaAutomaticMigrationsBundle: PublishContentTypeDraftEvent received', ['event' => get_class($event)]);

        // Skip in CLI to prevent creating redundant migrations when executing migrations that create/update content types
        if ($this->isCli) {
            $this->logger->info('IbexaAutomaticMigrationsBundle: Skipping in CLI to avoid redundant migrations during execution');
            return;
        }

        $contentTypeDraft = $event->getContentTypeDraft();
        $this->logger->info('PublishContentTypeDraftEvent received', ['id' => $contentTypeDraft->id, 'identifier' => $contentTypeDraft->identifier]);

        // Load the published content type (with the new values after publishing)
        $contentTypeService = $this->container->get('ibexa.api.service.content_type');
        try {
            $publishedContentType = $contentTypeService->loadContentType($contentTypeDraft->id);
            $this->logger->info('Published content type loaded successfully', ['id' => $publishedContentType->id, 'identifier' => $publishedContentType->identifier]);
            
            // Check if a CREATE migration was ever executed for this identifier
            // This tells us if it's new or an update
            $hasExecutedCreate = $this->hasExecutedCreateMigration($publishedContentType->identifier);
            $mode = $hasExecutedCreate ? 'update' : 'create';
            
            $this->logger->info('Determined migration mode based on executed migrations', [
                'mode' => $mode,
                'identifier' => $publishedContentType->identifier,
                'has_executed_create' => $hasExecutedCreate
            ]);
            
            // Generate migration for both creates and updates (with the published/new values)
            $this->generateMigration($publishedContentType, $mode);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to process published content type', ['id' => $contentTypeDraft->id, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }

    #[AsEventListener(BeforeDeleteContentTypeEvent::class)]
    public function onIbexaBeforeDeleteContentType(BeforeDeleteContentTypeEvent $event): void
    {
        $env = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null;
        if ($env !== 'dev') {
            $this->logger->info('IbexaAutomaticMigrationsBundle: Skipping migration generation, not in dev environment');
            return;
        }
        $this->logger->info('IbexaAutomaticMigrationsBundle: BeforeDeleteContentTypeEvent received', ['event' => get_class($event)]);

        // Skip in CLI to prevent creating redundant migrations when executing migrations that delete content types
        if ($this->isCli) {
            $this->logger->info('IbexaAutomaticMigrationsBundle: Skipping in CLI to avoid redundant migrations during execution');
            return;
        }

        $contentType = $event->getContentType();
        $this->logger->info('BeforeDeleteContentTypeEvent received', ['id' => $contentType->id, 'identifier' => $contentType->identifier]);

        // Generate delete migration BEFORE the content type is deleted
        $this->generateMigration($contentType, 'delete');
    }



    private function hasExecutedCreateMigration(string $identifier): bool
    {
        try {
            $conn = $this->container->get('doctrine.dbal.default_connection');
            
            if ($this->mode === 'kaliop') {
                $table = 'kaliop_migrations';
                $column = 'migration';
                $statusColumn = 'status';
                $executedStatus = 2; // STATUS_DONE
                
                // Check if any executed CREATE migration exists for this identifier
                $sql = "SELECT COUNT(*) as count FROM $table WHERE $column LIKE ? AND $statusColumn = ?";
                $result = $conn->executeQuery($sql, [
                    '%_content_type_create_' . $identifier . '.%',
                    $executedStatus,
                ])->fetchAssociative();
            } elseif ($this->mode === 'ibexa') {
                $table = 'ibexa_migrations';
                $column = 'name';
                $executedAtColumn = 'executed_at';
                
                // Check if any executed CREATE migration exists for this identifier
                // A migration is considered executed if executed_at is not null
                $sql = "SELECT COUNT(*) as count FROM $table WHERE $column LIKE ? AND $executedAtColumn IS NOT NULL";
                $result = $conn->executeQuery($sql, [
                    '%_auto_content_type_create_' . $identifier . '.%',
                ])->fetchAssociative();
            } else {
                // If mode is not set, assume no executed migration exists
                return false;
            }
            
            $count = (int)($result['count'] ?? 0);
            $this->logger->info('Checked for executed CREATE migrations', [
                'identifier' => $identifier,
                'mode' => $this->mode,
                'count' => $count
            ]);
            
            // Return true if any executed CREATE migration was found
            return $count > 0;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to check for executed CREATE migrations', [
                'exception' => $e->getMessage(),
                'mode' => $this->mode,
                'identifier' => $identifier
            ]);
            // If we can't check the DB, assume no executed migration exists (safer default)
            return false;
        }
    }

    private function generateMigration(\Ibexa\Contracts\Core\Repository\Values\ContentType\ContentType|\Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeDraft $contentType, string $mode): void
    {
        $this->logger->info('Starting migration generation', ['mode' => $mode, 'identifier' => $contentType->identifier, 'id' => $contentType->id]);
        
        if ($this->mode !== 'kaliop' && $this->mode !== 'ibexa') {
            $this->logger->info('Skipping migration generation - not using kaliop or ibexa mode', ['current_mode' => $this->mode]);
            return;
        }

        try {
            $matchValue = $contentType->identifier;
            $name = 'auto_content_type_' . $mode . '_' . (string) $matchValue;

            if ($this->mode === 'kaliop') {
                $inputArray = [
                    '--format' => 'yml',
                    '--type' => 'content_type',
                    '--mode' => $mode,
                    '--match-type' => 'content_type_identifier',
                    '--match-value' => (string) $matchValue,
                    'bundle' => $this->destination,
                    'name' => $name,
                ];
                $command = 'kaliop:migration:generate';
            } elseif ($this->mode === 'ibexa') {
                $now = new \DateTime();
                $inputArray = [
                    '--format' => 'yaml',
                    '--type' => 'content_type',
                    '--mode' => $mode,
                    '--match-property' => 'content_type_identifier',
                    '--value' => (string) $matchValue,
                    '--file' => $now->format('Y_m_d_H_i_s_') . $name . '.yaml',
                ];
                $command = 'ibexa:migrations:generate';
            }

            $flags = [];
            foreach ($inputArray as $k => $v) {
                if (str_starts_with((string) $k, '--') || str_starts_with((string) $k, '-')) {
                    $flags[] = $k . '=' . $v;
                }
            }
            $cmd = array_merge($this->consoleCommand, [
                $command,
            ], $flags);
            if ($this->mode === 'kaliop') {
                $cmd = array_merge($cmd, [$this->destination, $name]);
            }
            $process = new Process($cmd, $this->projectDir);

            $process->run();
            $code = $process->getExitCode();
            $this->logger->info('Migration generate process finished (' . $mode . ')', ['name' => $name, 'code' => $code, 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()]);
            if ($code == 0) {
                if ($this->mode === 'ibexa') {
                    $fileName = $inputArray['--file'];
                    $this->logger->info('Using specified filename for ibexa', ['fileName' => $fileName]);
                } else {
                    // Find the newest migration file in the destination directory for kaliop
                    $files = glob($this->destination . DIRECTORY_SEPARATOR . '*.{yml,yaml}', GLOB_BRACE);
                    $latestFile = '';
                    $latestTime = 0;
                    foreach ($files as $file) {
                        $mtime = filemtime($file);
                        if ($mtime > $latestTime) {
                            $latestTime = $mtime;
                            $latestFile = $file;
                        }
                    }
                    if ($latestFile) {
                        $fileName = basename($latestFile);
                        $this->logger->info('Newest migration file found for kaliop', ['fileName' => $fileName, 'path' => $latestFile]);
                    } else {
                        $this->logger->warning('No migration files found after generation for kaliop');
                        return;
                    }
                }

                $fullPath = $this->destination . DIRECTORY_SEPARATOR . $fileName;

                if (!$this->fixMigrationYaml($fullPath)) {
                    $this->logger->error('Aborting: generated migration has YAML errors', ['file' => $fullPath]);
                    return;
                }

                $md5 = md5_file($fullPath);
                try {
                    if ($this->mode === 'ibexa') {
                        $conn = $this->container->get('doctrine.dbal.default_connection');
                        $table = 'ibexa_migrations';
                        $data = [
                            'executed_at' => new \DateTime(),
                            'execution_time' => null
                        ];
                        $identifier = ['name' => $fileName];
                        $affected = $conn->update($table, $data, $identifier);
                        if ($affected === 0) {
                            // Row not found, insert
                            $conn->insert($table, array_merge($identifier, $data));
                        }
                    } elseif ($this->mode === 'kaliop') {
                        $conn = $this->container->get('doctrine.dbal.default_connection');
                        $table = 'kaliop_migrations';
                        $data = [
                            'execution_date' => time(),
                            'status' => 2  // STATUS_DONE
                        ];
                        $identifier = ['migration' => $fileName];
                        $affected = $conn->update($table, $data, $identifier);
                        if ($affected === 0) {
                            // Row not found, insert
                            $conn->insert($table, array_merge($identifier, $data, [
                                'md5' => md5_file($fullPath),
                                'path' => $fullPath,
                                'execution_error' => null
                            ]));
                        }
                        $this->logger->info('Migration marked as executed in DB', ['filename' => $fileName, 'table' => $table, 'action' => $affected > 0 ? 'updated' : 'inserted']);
                    }
                    $this->logger->info('Migration marked as executed', ['filename' => $fileName, 'mode' => $this->mode]);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to mark migration as executed', ['exception' => $e->getMessage()]);
                }
            } else {
                $this->logger->error('Migration generation failed', ['code' => $code, 'error' => $process->getErrorOutput()]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate migration programmatically', ['mode' => $mode, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }

    /**
     * Validates YAML syntax and ensures all ibexa_string / ibexa_integer / ibexa_boolean
     * attribute blocks carry the required field-settings and validator-configuration keys
     * so that Ibexa's buildSPIFieldDefinitionFromUpdateStruct does not fall back to
     * potentially stale stored settings and reject them.
     *
     * Returns true on success, false when a YAML parse error was detected.
     */
    private function fixMigrationYaml(string $fullPath): bool
    {
        $content = file_get_contents($fullPath);
        if ($content === false) {
            return true;
        }

        // Quick syntax check
        try {
            Yaml::parse($content);
        } catch (ParseException $e) {
            $this->logger->error('Generated migration has invalid YAML syntax', [
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
                            // First non-empty, non-8-space line after match: block → insert before it
                            $newStep[] = '    match_tolerate_misses: true';
                            $inserted = true;
                            $inMatchBlock = false;
                        }
                    }
                    $newStep[] = $sLine;
                }

                // match: was the final key in the step
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
            $this->logger->info('Fixed migration YAML: added missing field-settings/validator-configuration/match_tolerate_misses', [
                'file' => $fullPath,
            ]);
        }

        return true;
    }
}
