<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Enum\EventStatus;
use App\Enum\EventType;
use App\Enum\Provider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

final class EventCrudController extends AbstractCrudController
{
    private const DATETIME_FORMAT = 'yyyy-MM-dd HH:mm:ss';

    public function __construct(private readonly AdminUrlGenerator $adminUrlGenerator)
    {
    }

    public static function getEntityFqcn(): string
    {
        return Event::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Event')
            ->setEntityLabelInPlural('Events')
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(50)
            ->setSearchFields(['eventId', 'currency', 'inboundRequest.remoteIp'])
            ->showEntityActionsInlined();
    }

    public function configureActions(Actions $actions): Actions
    {
        $transactions = Action::new('transactions', 'Transactions', 'fa fa-list')
            ->linkToUrl(fn (Event $event): string => $this->adminUrlGenerator
                ->setController(TransactionCrudController::class)
                ->setAction(Action::INDEX)
                ->set('filters', ['eventId' => ['comparison' => '=', 'value' => $event->getEventId()]])
                ->generateUrl());

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $transactions)
            ->add(Crud::PAGE_DETAIL, $transactions)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('eventId', 'Event ID'))
            ->add(ChoiceFilter::new('provider')->setChoices(self::choices(Provider::cases())))
            ->add(ChoiceFilter::new('type')->setChoices(self::choices(EventType::cases())))
            ->add(ChoiceFilter::new('status')->setChoices(self::choices(EventStatus::cases())));
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addFieldset('Event', 'fa fa-bolt')->onlyOnDetail();
        yield IdField::new('id');
        yield ChoiceField::new('provider')->setChoices(self::labels(Provider::cases()))->renderAsBadges(['bank' => 'info', 'card' => 'primary']);
        yield TextField::new('eventId', 'Event ID');
        yield ChoiceField::new('type')->setChoices(self::labels(EventType::cases()))->renderAsBadges();
        yield NumberField::new('amount')->setNumDecimals(2);
        yield TextField::new('currency');
        yield ChoiceField::new('status')->setChoices(self::labels(EventStatus::cases()))->renderAsBadges([
            EventStatus::Pending->value => 'warning',
            EventStatus::Processed->value => 'success',
            EventStatus::Duplicate->value => 'secondary',
        ]);
        yield DateTimeField::new('occurredAt', 'Occurred')->setFormat(self::DATETIME_FORMAT);

        yield FormField::addFieldset('Intake', 'fa fa-inbox')->onlyOnDetail();
        yield DateTimeField::new('inboundRequest.receivedAt', 'Received at')->setFormat(self::DATETIME_FORMAT)->onlyOnDetail();
        yield DateTimeField::new('processedAt', 'Processed at')->setFormat(self::DATETIME_FORMAT)->onlyOnDetail();
        yield TextField::new('inboundRequest.remoteIp', 'Sender IP')->onlyOnDetail();
        yield TextareaField::new('inboundRequest.userAgent', 'User agent')->onlyOnDetail();
        yield TextField::new('inboundRequest.method', 'Method')->onlyOnDetail();
        yield TextareaField::new('inboundRequest.uri', 'URL')->onlyOnDetail();
        yield TextField::new('inboundRequest.contentType', 'Content type')->onlyOnDetail();
        yield Field::new('inboundRequest.headers', 'Headers')->setTemplatePath('admin/field/headers.html.twig')->onlyOnDetail();
        yield TextareaField::new('inboundRequest.body', 'Request body')->setTemplatePath('admin/field/json.html.twig')->onlyOnDetail();
        yield IntegerField::new('inboundRequest.responseStatus', 'Response status')->setTemplatePath('admin/field/http_status.html.twig')->onlyOnDetail();

        yield DateTimeField::new('createdAt', 'Received')->setFormat(self::DATETIME_FORMAT)->onlyOnIndex();
        yield DateTimeField::new('processedAt', 'Processed')->setFormat(self::DATETIME_FORMAT)->onlyOnIndex();
    }

    private static function choices(array $cases): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[$case->value] = $case;
        }

        return $choices;
    }

    private static function labels(array $cases): array
    {
        $labels = [];
        foreach ($cases as $case) {
            $labels[$case->value] = $case->value;
        }

        return $labels;
    }
}
