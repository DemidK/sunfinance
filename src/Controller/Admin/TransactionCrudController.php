<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Transaction;
use App\Enum\Account;
use App\Enum\Direction;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;

final class TransactionCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Transaction::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Transaction')
            ->setEntityLabelInPlural('Transactions')
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(50)
            ->setSearchFields(['eventId', 'currency']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('eventId', 'Event ID'))
            ->add(ChoiceFilter::new('account')->setChoices(self::choices(Account::cases())))
            ->add(ChoiceFilter::new('direction')->setChoices(self::choices(Direction::cases())))
            ->add(TextFilter::new('currency'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield TextField::new('eventId', 'Event ID');
        yield ChoiceField::new('account')->setChoices(self::labels(Account::cases()))->renderAsBadges();
        yield ChoiceField::new('direction')->setChoices(self::labels(Direction::cases()))->renderAsBadges([
            Direction::Debit->value => 'success',
            Direction::Credit->value => 'warning',
        ]);
        yield NumberField::new('amount')->setNumDecimals(2);
        yield TextField::new('currency');
        yield DateTimeField::new('createdAt', 'Created')->setFormat('yyyy-MM-dd HH:mm:ss');
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
