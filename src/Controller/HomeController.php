<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('home/index.html.twig', [
            'php_version' => \PHP_VERSION,
            'symfony_version' => \Symfony\Component\HttpKernel\Kernel::VERSION,
            'environment' => $this->getParameter('kernel.environment'),
        ]);
    }
}
