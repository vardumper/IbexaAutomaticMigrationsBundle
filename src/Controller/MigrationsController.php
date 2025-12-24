<?php declare(strict_types=1);

namespace vardumper\IbexaAutomaticMigrationsBundle\Controller;

use Ibexa\Contracts\AdminUi\Controller\Controller;
use Ibexa\Contracts\Migration\Metadata\Storage\MetadataStorage;
use Ibexa\Migration\MigrationService;
use Ibexa\Migration\Metadata\ExecutionResult;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use vardumper\IbexaAutomaticMigrationsBundle\Helper\Helper;

#[AsController]
class MigrationsController extends Controller
{
    private string $projectDir;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
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
            
            if (!empty($selectedMigrations)) {
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
        usort($migrationsWithStatus, function($a, $b) use ($sort, $direction) {
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
        // Find migration files (Kaliop convention: *.php or *.yml in $destination)
        $files = glob($destination . DIRECTORY_SEPARATOR . '*.{php,yml}', GLOB_BRACE);
        foreach ($files as $file) {
            $name = basename($file);
            $createdAt = filemtime($file);
            // Use CLI to check if migration is executed
            $isExecuted = $this->isKaliopMigrationExecuted($name);
            $executedAt = $isExecuted ? $this->getKaliopMigrationExecutedAt($name) : null;
            $migrationsWithStatus[] = [
                'migration' => (object)['name' => $name],
                'isExecuted' => $isExecuted,
                'createdAt' => $createdAt,
                'executedAt' => $executedAt,
            ];
        }
        return $migrationsWithStatus;
    }

    /**
     * Checks if a Kaliop migration is executed by calling the CLI tool.
     */
    private function isKaliopMigrationExecuted(string $migrationName): bool
    {
        $cmd = sprintf('php bin/console kaliop:migration:status %s --no-interaction', escapeshellarg($migrationName));
        $output = shell_exec($cmd);
        return is_string($output) && str_contains($output, 'executed');
    }

    /**
     * Gets the execution date of a Kaliop migration (if available).
     */
    private function getKaliopMigrationExecutedAt(string $migrationName): ?\DateTimeImmutable
    {
        $cmd = sprintf('php bin/console kaliop:migration:status %s --no-interaction', escapeshellarg($migrationName));
        $output = shell_exec($cmd);
        if (is_string($output) && preg_match('/executed at ([0-9\- :]+)/', $output, $matches)) {
            return new \DateTimeImmutable(trim($matches[1]));
        }
        return null;
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
            foreach ($migrationNames as $migrationName) {
                switch ($action) {
                    case 'execute':
                        $cmd = sprintf('php bin/console kaliop:migration:execute %s --no-interaction', escapeshellarg($migrationName));
                        shell_exec($cmd);
                        break;
                    case 'mark_executed':
                        $cmd = sprintf('php bin/console kaliop:migration:mark-executed %s --no-interaction', escapeshellarg($migrationName));
                        shell_exec($cmd);
                        break;
                    case 'mark_pending':
                        $cmd = sprintf('php bin/console kaliop:migration:mark-pending %s --no-interaction', escapeshellarg($migrationName));
                        shell_exec($cmd);
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