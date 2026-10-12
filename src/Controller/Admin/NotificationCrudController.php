<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\InboundRequest;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\NullFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

final class NotificationCrudController extends AbstractCrudController
{
    private const DATETIME_FORMAT = 'yyyy-MM-dd HH:mm:ss';
    private const STATUSES = [202, 400, 404, 405, 422, 500];

    public static function getEntityFqcn(): string
    {
        return InboundRequest::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Notification')
            ->setEntityLabelInPlural('Notifications')
            ->setPageTitle(Crud::PAGE_DETAIL, fn (InboundRequest $request): string => sprintf('Notification #%d', $request->getId()))
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(50)
            ->setSearchFields(['uri', 'body', 'responseBody', 'remoteIp', 'userAgent'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        $statuses = [];
        foreach (self::STATUSES as $status) {
            $statuses[(string) $status] = $status;
        }

        return $filters
            ->add(ChoiceFilter::new('responseStatus', 'Response status')->setChoices($statuses))
            ->add(NullFilter::new('event', 'Accepted')->setChoiceLabels('Rejected', 'Accepted'))
            ->add(TextFilter::new('uri', 'URL'))
            ->add(TextFilter::new('remoteIp', 'Sender IP'))
            ->add(DateTimeFilter::new('receivedAt', 'Received'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('Request', 'fa fa-arrow-right-to-bracket')->onlyOnDetail();
        yield IdField::new('id');
        yield DateTimeField::new('receivedAt', 'Received')->setFormat(self::DATETIME_FORMAT);
        yield TextField::new('method');
        yield TextField::new('path', 'Endpoint')->onlyOnIndex();
        yield IntegerField::new('responseStatus', 'Response')->setTemplatePath('admin/field/http_status.html.twig')->onlyOnIndex();
        yield AssociationField::new('event')
            ->formatValue(static fn ($value, InboundRequest $request): ?string => $request->getEvent()?->getEventId());
        yield TextField::new('remoteIp', 'Sender IP');
        yield TextareaField::new('uri', 'URL')->onlyOnDetail();
        yield TextareaField::new('userAgent', 'User agent')->onlyOnDetail();
        yield TextField::new('contentType', 'Content type')->onlyOnDetail();
        yield Field::new('headers')->setTemplatePath('admin/field/headers.html.twig')->onlyOnDetail();
        yield TextareaField::new('body', 'Request body')->setTemplatePath('admin/field/json.html.twig')->onlyOnDetail();

        yield FormField::addFieldset('Response', 'fa fa-reply')->onlyOnDetail();
        yield IntegerField::new('responseStatus', 'Status')->setTemplatePath('admin/field/http_status.html.twig')->onlyOnDetail();
        yield TextareaField::new('responseBody', 'Response body')->setTemplatePath('admin/field/json.html.twig')->onlyOnDetail();
    }
}
