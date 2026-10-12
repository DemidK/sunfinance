<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(private readonly AdminUrlGenerator $adminUrlGenerator)
    {
    }

    public function index(): Response
    {
        return $this->redirect($this->adminUrlGenerator->setController(TransactionCrudController::class)->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Financial Events')
            ->setLocales(['en']);
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkTo(TransactionCrudController::class, 'Transactions', 'fa fa-list');
        yield MenuItem::linkTo(EventCrudController::class, 'Events', 'fa fa-bolt');
        yield MenuItem::linkTo(NotificationCrudController::class, 'Notifications', 'fa fa-inbox');
        yield MenuItem::section();
        yield MenuItem::linkToUrl('Welcome page', 'fa fa-home', '/');
    }

    public function configureUserMenu(UserInterface $user): UserMenu
    {
        return parent::configureUserMenu($user)
            ->setName($user->getUserIdentifier())
            ->displayUserAvatar(false);
    }
}
