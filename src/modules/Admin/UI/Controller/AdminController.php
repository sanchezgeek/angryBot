<?php

namespace App\Admin\UI\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AdminController extends AbstractController
{
    #[Route(path: '/admin/dashboard{wildcard}', requirements: ['wildcard' => '.*'])]
    public function dashboard(): Response
    {
        return $this->render('@admin/dashboard.html.twig');
    }
}
