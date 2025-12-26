<?php

declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\Controller;

use Doctrine\DBAL\Connection;
use Ibexa\Contracts\AdminUi\Controller\Controller;
use Ibexa\Contracts\Migration\Metadata\Storage\MetadataStorage;
use Ibexa\Migration\Metadata\ExecutionResult;
use Ibexa\Migration\MigrationService;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use vardumper\IbexaAutomaticMigrationsBundle\Helper\Helper;
use vardumper\IbexaAutomaticMigrationsBundle\Service\SettingsService;

#[AsController]
final class MigrationsController extends Controller
{
    private string $projectDir;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        #[Autowire(service: 'ibexa.api.storage_engine.legacy.connection')]
        private readonly Connection $connection,
        private readonly SettingsService $settingsService,
        private readonly ?MigrationService $migrationService = null,
        private readonly ?MetadataStorage $metadataStorage = null,
    ) {
        $this->projectDir = rtrim($projectDir, DIRECTORY_SEPARATOR);
    }

    #[Route('/migrations', name: 'migrations_list')]
    public function listAction(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        $sort = $request->query->get('sort', 'name');
        $direction = $request->query->get('direction', 'asc');
        
        // Handle POST actions
        if ($request->isMethod('POST')) {
            $action = $request->request->get('bulk-action');
            $selectedMigrations = $request->request->all('migrations');
            
            if (!empty($selectedMigrations) && $action) {
                $this->handleBulkAction($action, $selectedMigrations);
                
                // Redirect to refresh the page
                return $this->redirectToRoute('migrations_list', [
                    'page' => $page,
                    'sort' => $sort,
                    'direction' => $direction,
                ]);
            }
        }
        
        $mode = Helper::determineMode();
        $destination = Helper::determineDestination($this->projectDir);
        if ($mode === 'ibexa') {
            $all = $this->migrationService->listMigrations();
            // Add execution status to each migration
            $executedMigrations = $this->metadataStorage->getExecutedMigrations();
            $migrationsWithStatus = [];
            foreach ($all as $migration) {
                $path = $destination . DIRECTORY_SEPARATOR . $migration->getName();
                $createdAt = null;
                if (file_exists($path)) {
                    // Use file modification time as it's more reliable than creation time
                    $createdAt = filemtime($path);
                }
                $executedAt = null;
                if ($executedMigrations->hasMigration($migration->getName())) {
                    $executedMigration = $executedMigrations->getMigration($migration->getName());
                    $executedAt = $executedMigration->getExecutedAt();
                }
                $migrationsWithStatus[] = [
                    'migration' => $migration,
                    'isExecuted' => $this->migrationService->isMigrationExecuted($migration),
                    'createdAt' => $createdAt,
                    'executedAt' => $executedAt,
                ];
            }
        } elseif ($mode === 'kaliop') {
            // Kaliop mode: scan migration files and check status via CLI
            $migrationsWithStatus = $this->getKaliopMigrationsWithStatus($destination);
        } else {
            throw new \RuntimeException('Neither ibexa/migrations nor kaliop/ibexa-migration-bundle is installed. Please install one of them to use the Migrations functionality.');
        }
        
        // Sort the migrations
        usort($migrationsWithStatus, function ($a, $b) use ($sort, $direction) {
            $valueA = $this->getSortValue($a, $sort);
            $valueB = $this->getSortValue($b, $sort);
            
            if ($direction === 'desc') {
                return $valueB <=> $valueA;
            }
            return $valueA <=> $valueB;
        });
        
        $paginator = new Pagerfanta(new ArrayAdapter($migrationsWithStatus));
        $paginator->setMaxPerPage(25);
        $paginator->setCurrentPage($page);
        return $this->render('@IbexaAutomaticMigrationsBundle/migrations/list.twig', [
            'migrations' => $paginator,
            'sort' => $sort,
            'direction' => $direction,
            'mode' => $mode,
        ]);
    }

    #[Route('/migrations/settings', name: 'migrations_settings')]
    public function settingsAction(Request $request): Response
    {
        $settings = $this->settingsService->getSettings();

        if ($request->isMethod('POST')) {
            $settings['enabled'] = $request->request->getBoolean('enabled');
            $settings['types']['content_type'] = $request->request->getBoolean('content_type');
            $settings['types']['content_type_group'] = $request->request->getBoolean('content_type_group');
            $settings['types']['section'] = $request->request->getBoolean('section');
            $settings['types']['object_state'] = $request->request->getBoolean('object_state');
            $settings['types']['object_state_group'] = $request->request->getBoolean('object_state_group');
            $settings['types']['user'] = $request->request->getBoolean('user');
            $settings['types']['role'] = $request->request->getBoolean('role');
            $settings['types']['language'] = $request->request->getBoolean('language');
            $settings['types']['url'] = $request->request->getBoolean('url');

            $this->settingsService->saveSettings($settings);

            $this->addFlash('success', 'Settings saved successfully.');

            return $this->redirectToRoute('migrations_settings');
        }

        return $this->render('@IbexaAutomaticMigrationsBundle/migrations/settings.twig', [
            'settings' => $settings,
        ]);
    }
    
    private function getSortValue(array $migrationData, string $sort): mixed
    {
        return match ($sort) {
            'name' => $migrationData['migration']->name,
            'status' => $migrationData['isExecuted'] ? 1 : 0,
            'createdAt' => $migrationData['createdAt'] ?? 0,
            'executedAt' => $migrationData['executedAt'] ? $migrationData['executedAt']->getTimestamp() : 0,
            default => $migrationData['migration']->name,
        };
    }
    
    /**
     * Returns an array of migrations with status for Kaliop mode.
     */
    private function getKaliopMigrationsWithStatus(string $destination): array
    {
        $migrationsWithStatus = [];
        $conn = $this->connection;
        $dbMigrations = [];
        try {
            $sql = "SELECT migration, status, execution_date FROM kaliop_migrations";
            $stmt = $conn->executeQuery($sql);
            while ($row = $stmt->fetchAssociative()) {
                $dbMigrations[$row['migration']] = $row;
            }
        } catch (\Exception $e) {
            // Table may not exist
        }

        // Find migration files
        $files = glob($destination . DIRECTORY_SEPARATOR . '*.{php,yml,yaml}', GLOB_BRACE);
        foreach ($files as $file) {
            $name = basename($file);
            $createdAt = filemtime($file);
            $isExecuted = false;
            $executedAt = null;
            if (isset($dbMigrations[$name])) {
                $isExecuted = $dbMigrations[$name]['status'] == 2; // STATUS_DONE
                if ($isExecuted && $dbMigrations[$name]['execution_date']) {
                    $executedAt = \DateTimeImmutable::createFromFormat('U', (string)$dbMigrations[$name]['execution_date']);
                }
            }
            $migrationsWithStatus[] = [
                'migration' => (object)['name' => $name],
                'isExecuted' => $isExecuted,
                'createdAt' => $createdAt,
                'executedAt' => $executedAt,
            ];
        }
        return $migrationsWithStatus;
    }
    
    private function handleBulkAction(string $action, array $migrationNames): void
    {
        $mode = Helper::determineMode();
        if ($mode === 'ibexa') {
            foreach ($migrationNames as $migrationName) {
                $migration = $this->migrationService->findOneByName($migrationName);
                if (!$migration) {
                    continue; // Skip if migration not found
                }
                
                switch ($action) {
                    case 'execute':
                        if (!$this->migrationService->isMigrationExecuted($migration)) {
                            try {
                                $this->migrationService->executeOne($migration);
                            } catch (\Exception $e) {
                                // Log error but continue with other migrations
                                error_log("Failed to execute migration {$migrationName}: " . $e->getMessage());
                            }
                        }
                        break;
                        
                    case 'mark_executed':
                        if (!$this->migrationService->isMigrationExecuted($migration)) {
                            $result = new ExecutionResult($migrationName);
                            $result->setExecutedAt(new \DateTimeImmutable());
                            $this->metadataStorage->complete($result);
                        }
                        break;
                        
                    case 'mark_pending':
                        if ($this->migrationService->isMigrationExecuted($migration)) {
                            $this->metadataStorage->reset();
                            $allMigrations = $this->migrationService->listMigrations();
                            foreach ($allMigrations as $m) {
                                if ($m->getName() !== $migrationName && !$this->migrationService->isMigrationExecuted($m)) {
                                    try {
                                        $this->migrationService->executeOne($m);
                                    } catch (\Exception $e) {
                                    }
                                }
                            }
                        }
                        break;
                        
                    case 'delete':
                        if ($this->migrationService->isMigrationExecuted($migration)) {
                            $this->metadataStorage->reset();
                            $allMigrations = $this->migrationService->listMigrations();
                            foreach ($allMigrations as $m) {
                                if ($m->getName() !== $migrationName && !$this->migrationService->isMigrationExecuted($m)) {
                                    try {
                                        $this->migrationService->executeOne($m);
                                    } catch (\Exception $e) {
                                    }
                                }
                            }
                        }
                        break;
                }
            }
        } elseif ($mode === 'kaliop') {
            $destination = Helper::determineDestination($this->projectDir);
            foreach ($migrationNames as $migrationName) {
                switch ($action) {
                    case 'execute':
                        $cmd = sprintf('php bin/console kaliop:migration:migration execute %s %s --no-interaction', escapeshellarg($migrationName), escapeshellarg($destination));
                        shell_exec($cmd);
                        break;
                    case 'mark_executed':
                        $conn = $this->connection;
                        $table = 'kaliop_migrations';
                        $data = [
                            'execution_date' => time(),
                            'status' => 2  // STATUS_DONE
                        ];
                        $identifier = ['migration' => $migrationName];
                        $affected = $conn->update($table, $data, $identifier);
                        if ($affected === 0) {
                            // Row not found, insert
                            $fullPath = $destination . DIRECTORY_SEPARATOR . $migrationName;
                            $conn->insert($table, array_merge($identifier, $data, [
                                'md5' => file_exists($fullPath) ? md5_file($fullPath) : '',
                                'path' => $fullPath,
                                'execution_error' => null
                            ]));
                        }
                        break;
                    case 'mark_pending':
                        // For Kaliop, mark as pending by setting status to TODO
                        $conn = $this->connection;
                        $table = 'kaliop_migrations';
                        $data = [
                            'status' => 0  // STATUS_TODO
                        ];
                        $identifier = ['migration' => $migrationName];
                        $affected = $conn->update($table, $data, $identifier);
                        if ($affected === 0) {
                            // Row not found, insert with TODO
                            $fullPath = $destination . DIRECTORY_SEPARATOR . $migrationName;
                            $conn->insert($table, array_merge($identifier, $data, [
                                'execution_date' => null,
                                'md5' => file_exists($fullPath) ? md5_file($fullPath) : '',
                                'path' => $fullPath,
                                'execution_error' => null
                            ]));
                        }
                        break;
                    case 'delete':
                        $destination = Helper::determineDestination($this->projectDir);
                        $file = $destination . DIRECTORY_SEPARATOR . $migrationName;
                        if (file_exists($file)) {
                            unlink($file);
                        }
                        break;
                }
            }
        }
    }
}
