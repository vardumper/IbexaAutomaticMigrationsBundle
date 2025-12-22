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
        private readonly MigrationService $migrationService,
        private readonly MetadataStorage $metadataStorage,
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
        $all = match ($mode) {
            'ibexa' => $this->migrationService->listMigrations(), 
            'kaliop' => null,
            default => throw new \RuntimeException('Neither ibexa/migrations nor kaliop/ibexa-migration-bundle is installed. Please install one of them to use the Migrations functionality.'),
        };
        
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
        ]);
    }
    
    private function getSortValue(array $migrationData, string $sort): mixed
    {
        return match ($sort) {
            'name' => $migrationData['migration']->getName(),
            'status' => $migrationData['isExecuted'] ? 1 : 0,
            'createdAt' => $migrationData['createdAt'] ?? 0,
            'executedAt' => $migrationData['executedAt'] ? $migrationData['executedAt']->getTimestamp() : 0,
            default => $migrationData['migration']->getName(),
        };
    }
    
    private function handleBulkAction(string $action, array $migrationNames): void
    {
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
                        // Manually mark as executed in metadata storage
                        $result = new ExecutionResult($migrationName);
                        $result->setExecutedAt(new \DateTimeImmutable());
                        $this->metadataStorage->complete($result);
                    }
                    break;
                    
                case 'mark_pending':
                    if ($this->migrationService->isMigrationExecuted($migration)) {
                        // Remove from executed migrations metadata
                        // Note: This is a simplified approach - in a real implementation,
                        // you might want to add a method to remove from metadata storage
                        $this->metadataStorage->reset();
                        // Re-execute all other migrations to restore metadata
                        $allMigrations = $this->migrationService->listMigrations();
                        foreach ($allMigrations as $m) {
                            if ($m->getName() !== $migrationName && !$this->migrationService->isMigrationExecuted($m)) {
                                try {
                                    $this->migrationService->executeOne($m);
                                } catch (\Exception $e) {
                                    // Continue
                                }
                            }
                        }
                    }
                    break;
                    
                case 'delete':
                    // Note: This is a simplified approach. In a real implementation,
                    // you would need to handle file deletion and metadata cleanup
                    // For now, we'll just remove from metadata if executed
                    if ($this->migrationService->isMigrationExecuted($migration)) {
                        $this->metadataStorage->reset();
                        // Re-execute all other migrations to restore metadata
                        $allMigrations = $this->migrationService->listMigrations();
                        foreach ($allMigrations as $m) {
                            if ($m->getName() !== $migrationName && !$this->migrationService->isMigrationExecuted($m)) {
                                try {
                                    $this->migrationService->executeOne($m);
                                } catch (\Exception $e) {
                                    // Continue
                                }
                            }
                        }
                    }
                    break;
            }
        }
    }
}

    // public function createAction(Request $request): Response
    // {
    //     $createForm = $this->formFactory->createNamed(
    //         'translation_create',
    //         TranslationType::class
    //     );
    //     $createForm->add('save', SubmitType::class, [
    //         'label' => 'Create Translation',
    //     ]);

    //     $createForm->handleRequest($request);
    //     if ($createForm->isSubmitted() && $createForm->isValid() && $createForm->getClickedButton() !== null) {
    //         $translationData = $createForm->getData();
    //         $entity = Translation::fromFormData($translationData);
    //         $this->entityManager->persist($entity);
    //         $this->entityManager->flush();
    //         $this->entityManager->clear();
    //         $this->cacheService->delete($entity->getLanguageCode(), $entity->getTransKey());

    //         return $this->redirectToRoute('translations.list');
    //     }

    //     return $this->render('@ibexadesign/translations/create.html.twig', [
    //         'form' => $createForm->createView(),
    //     ]);
    // }

    // public function editAction(Request $request, $id = null): Response
    // {
    //     // shouldn't happen, theres a route validation
    //     if ($id === null) {
    //         return new Response('No id provided', 404);
    //     }

    //     $editForm = $this->formFactory->createNamed(
    //         'translation_edit',
    //         TranslationType::class
    //     );

    //     $editForm->add(
    //         'id',
    //         HiddenType::class,
    //         [
    //             'data' => $id,
    //         ]
    //     );
    //     $editForm->add('save', SubmitType::class, [
    //         'label' => 'Save Changes',
    //     ]);

    //     // load existing entity
    //     $trans = $this->translationRepository->find($id);

    //     if ($request->isMethod('POST')) {
    //         $editForm->handleRequest($request);
    //         if ($editForm->isSubmitted() && $editForm->isValid()) {
    //             $trans->setTranslation($editForm->getData()->getTranslation()); // only update translation
    //             $this->entityManager->persist($trans);
    //             $this->entityManager->flush(); // save
    //             $this->entityManager->clear();

    //             $this->cacheService->delete($trans->getLanguageCode(), $trans->getTransKey());

    //             return $this->redirectToRoute('translations.list');
    //         }

    //         return $this->render('@ibexadesign/translations/edit.html.twig', [
    //             'form' => $editForm->createView(),
    //         ]);
    //     }

    //     $data = new Value();
    //     $data->setLanguageCode($trans->getLanguageCode());
    //     $data->setTransKey($trans->getTransKey());
    //     $data->setTranslation($trans->getTranslation());
    //     $editForm->setData($data);

    //     return $this->render('@ibexadesign/translations/edit.html.twig', [
    //         'form' => $editForm->createView(),
    //     ]);
    // }

    // public function deleteAction($id = null): Response
    // {
    //     // shouldn't happen, theres a route validation
    //     if ($id === null) {
    //         return new Response('No id provided', 404);
    //     }

    //     $entity = $this->translationRepository->find($id);
    //     $this->entityManager->remove($entity);
    //     $this->entityManager->flush();
    //     $this->entityManager->clear();

    //     $this->cacheService->delete($entity->getLanguageCode(), $entity->getTransKey());

    //     return $this->redirectToRoute('translations.list');
    // }

    // /**
    //  * @todo make sure special chars doesn't break cvs format
    //  */
    // public function exportAction(): Response
    // {
    //     $translations = $this->translationRepository->findAll();
    //     $fileName = sprintf('translation-export-%s.csv', time());
    //     $csv = Writer::createFromString();
    //     $csv->setDelimiter(';');
    //     $csv->setOutputBOM(Reader::BOM_UTF8);
    //     $csv->insertOne(['id', 'transKey', 'languageCode', 'translation']);
    //     $records = [];
    //     foreach ($translations as $translation) {
    //         $records[] = $translation->jsonSerialize();
    //     }
    //     $csv->insertAll($records);
    //     $response = new Response();
    //     $response->headers->set('Content-type', 'text/csv');
    //     $response->headers->set('Cache-Control', 'private');
    //     $response->headers->set('Content-Disposition', 'attachment; filename="' . $fileName . '";');
    //     $response->sendHeaders();
    //     $response->setContent($csv->toString());

    //     return $response;
    // }

    // public function importAction(Request $request): Response
    // {
    //     $form = $this->formFactory->createNamed(
    //         'translation_import',
    //         TranslationsImportType::class
    //     );

    //     $form->handleRequest($request);
    //     if ($form->isSubmitted() && $form->isValid()) {
    //         $mode = $form->getData()['mode'];

    //         if ($mode === 'truncate') {
    //             $this->translationRepository->truncate();
    //         }

    //         /** @var UploadedFile $csvFile */
    //         $csvFile = $form->get('csv')->getData();
    //         $reader = Reader::createFromPath($csvFile->getPathname(), 'r');

    //         // detect separator
    //         $tmp = new \SplFileObject($csvFile->getPathname());
    //         $tmp->seek(2);
    //         $line = $tmp->current();
    //         $separator = \str_contains($line, ';') ? ';' : ','; // new ; old ,
    //         $tmp = null; // remove file pointer

    //         // read the file
    //         $reader->setHeaderOffset(0);
    //         $reader->setDelimiter($separator);
    //         $records = $reader->getRecords(); //returns all the CSV records as an Iterator object

    //         foreach ($records as $record) {
    //             if ($mode === 'merge') {
    //                 try {
    //                     $entity = $this->translationRepository->findOneBy([
    //                         'transKey' => $record['transKey'],
    //                         'languageCode' => $record['languageCode'],
    //                     ]);
    //                 } catch (\Exception $e) {
    //                     // do nothing on errors
    //                     $entity = null;
    //                 }
    //                 if ($entity === null) {
    //                     continue;
    //                 }
    //                 $entity->setTranslation($record['translation']);
    //                 $entity->setTransKey($record['transKey']);
    //                 $entity->setLanguageCode($record['languageCode']);
    //             } else {
    //                 $entity = Translation::fromArray($record);
    //             }
    //             $this->entityManager->persist($entity);
    //             $this->cacheService->delete($entity->getLanguageCode(), $entity->getTransKey());
    //         }
    //         $this->entityManager->flush();
    //         $this->entityManager->clear();
    //         \unlink($csvFile->getPathname()); // delete the temp file

    //         return $this->redirectToRoute('translations.list');
    //     }

    //     return $this->render('@ibexadesign/translations/import.html.twig', [
    //         'form' => $form->createView(),
    //     ]);
    // }
