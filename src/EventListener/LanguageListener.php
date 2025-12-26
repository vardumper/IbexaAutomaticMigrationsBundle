<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\EventListener;

use Ibexa\Contracts\Core\Repository\Events\Language\CreateLanguageEvent;
use Ibexa\Contracts\Core\Repository\Events\Language\DeleteLanguageEvent;
use Ibexa\Contracts\Core\Repository\Events\Language\UpdateLanguageNameEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Process\Process;
use vardumper\IbexaAutomaticMigrationsBundle\Helper\Helper;
use vardumper\IbexaAutomaticMigrationsBundle\Service\SettingsService;

final class LanguageListener implements EventSubscriberInterface
{
    private bool $isCli = false;
    private ?string $mode = null;
    private string $projectDir;
    private string $destination;
    private ContainerInterface $container;
    private array $consoleCommand;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settingsService,
        string $projectDir,
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

    public static function getSubscribedEvents(): array
    {
        return [
            CreateLanguageEvent::class => 'onCreated',
            UpdateLanguageNameEvent::class => 'onUpdated',
            DeleteLanguageEvent::class => 'onDeleted',
        ];
    }

    public function onCreated(CreateLanguageEvent $event): void
    {
        if (!$this->settingsService->isEnabled() || !$this->settingsService->isTypeEnabled('language')) {
            return;
        }

        $this->logger->info('IbexaAutomaticMigrationsBundle: CreateLanguageEvent received', ['event' => get_class($event)]);

        // Skip in CLI to prevent creating redundant migrations when executing migrations that create/update languages
        if ($this->isCli) {
            $this->logger->info('IbexaAutomaticMigrationsBundle: Skipping in CLI to avoid redundant migrations during execution');
            return;
        }

        $language = $event->getLanguage();
        $this->logger->info('CreateLanguageEvent received', ['id' => $language->id, 'languageCode' => $language->languageCode, 'name' => $language->name]);

        $this->generateMigration($language, 'create');
    }

    public function onUpdated(UpdateLanguageNameEvent $event): void
    {
        if (!$this->settingsService->isEnabled() || !$this->settingsService->isTypeEnabled('language')) {
            return;
        }

        $this->logger->info('IbexaAutomaticMigrationsBundle: UpdateLanguageNameEvent received', ['event' => get_class($event)]);

        // Skip in CLI to prevent creating redundant migrations when executing migrations that create/update languages
        if ($this->isCli) {
            $this->logger->info('IbexaAutomaticMigrationsBundle: Skipping in CLI to avoid redundant migrations during execution');
            return;
        }

        $language = $event->getLanguage();
        $this->logger->info('UpdateLanguageNameEvent received', ['id' => $language->id, 'languageCode' => $language->languageCode, 'name' => $language->name]);

        $this->generateMigration($language, 'update');
    }

    public function onDeleted(DeleteLanguageEvent $event): void
    {
        if (!$this->settingsService->isEnabled() || !$this->settingsService->isTypeEnabled('language')) {
            return;
        }

        $this->logger->info('IbexaAutomaticMigrationsBundle: DeleteLanguageEvent received', ['event' => get_class($event)]);

        // Skip in CLI to prevent creating redundant migrations when executing migrations that delete languages
        if ($this->isCli) {
            $this->logger->info('IbexaAutomaticMigrationsBundle: Skipping in CLI to avoid redundant migrations during execution');
            return;
        }

        $language = $event->getLanguage();
        $this->logger->info('DeleteLanguageEvent received', ['id' => $language->id, 'languageCode' => $language->languageCode, 'name' => $language->name]);

        // Generate delete migration BEFORE the language is deleted
        $this->generateMigration($language, 'delete');
    }

    private function generateMigration(\Ibexa\Contracts\Core\Repository\Values\Content\Language $language, string $mode): void
    {
        $this->logger->info('Starting language migration generation', ['mode' => $mode, 'languageCode' => $language->languageCode, 'id' => $language->id]);

        if ($this->mode !== 'kaliop' && $this->mode !== 'ibexa') {
            $this->logger->info('Skipping migration generation - not using kaliop or ibexa mode', ['current_mode' => $this->mode]);
            return;
        }

        try {
            $matchValue = $language->languageCode;
            $name = 'auto_language_' . $mode . '_' . (string) $matchValue;

            if ($this->mode === 'kaliop') {
                $inputArray = [
                    '--format' => 'yml',
                    '--type' => 'language',
                    '--mode' => $mode,
                    '--match-type' => 'language_code',
                    '--match-value' => (string) $matchValue,
                    'bundle' => $this->destination,
                    'name' => $name,
                ];
                $command = 'kaliop:migration:generate';
            } elseif ($this->mode === 'ibexa') {
                $now = new \DateTime();
                $inputArray = [
                    '--format' => 'yaml',
                    '--type' => 'language',
                    '--mode' => $mode,
                    '--match-property' => 'language_code',
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
            $this->logger->info('Language migration generate process finished (' . $mode . ')', ['name' => $name, 'code' => $code, 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()]);
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
                $this->logger->error('Language migration generation failed', ['code' => $code, 'error' => $process->getErrorOutput()]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate language migration programmatically', ['mode' => $mode, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }
}
