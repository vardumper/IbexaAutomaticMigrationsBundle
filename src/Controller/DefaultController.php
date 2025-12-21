<?php

declare(strict_types=1);

namespace IbexaAutomaticMigrationsBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DefaultController extends AbstractController
{
    /**
     * @Route("/content-type-migrations", name="content_type_migrations_home")
     */
    public function index(): Response
    {
        return new Response('<html><body>ContentTypeMigrationsBundle is working!</body></html>');
    }
}
