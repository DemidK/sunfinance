<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\AccountBalance;
use App\Dto\TransactionListQuery;
use App\Entity\Transaction;
use App\Enum\Account;
use App\Enum\Direction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    public function existsForEvent(string $eventId): bool
    {
        return null !== $this->createQueryBuilder('t')
            ->select('t.id')
            ->where('t.eventId = :eventId')
            ->setParameter('eventId', $eventId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function balancesByAccount(Account $account): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.currency AS currency, t.direction AS direction, SUM(t.amount) AS total')
            ->where('t.account = :account')
            ->setParameter('account', $account->value)
            ->groupBy('t.currency, t.direction')
            ->orderBy('t.currency', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $totals = [];
        foreach ($rows as $row) {
            $currency = $row['currency'];
            $totals[$currency] ??= [Direction::Debit->value => '0.00', Direction::Credit->value => '0.00'];
            $direction = $row['direction'] instanceof Direction ? $row['direction']->value : $row['direction'];
            $totals[$currency][$direction] = bcadd((string) $row['total'], '0', 2);
        }

        $balances = [];
        foreach ($totals as $currency => $sums) {
            $balances[] = new AccountBalance($currency, $sums[Direction::Debit->value], $sums[Direction::Credit->value]);
        }

        return $balances;
    }

    public function countByAccount(Account $account, TransactionListQuery $query): int
    {
        return (int) $this->filtered($account, $query)
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findByAccount(Account $account, TransactionListQuery $query): array
    {
        return $this->filtered($account, $query)
            ->orderBy('t.id', 'DESC')
            ->setFirstResult($query->offset())
            ->setMaxResults($query->limit())
            ->getQuery()
            ->getResult();
    }

    private function filtered(Account $account, TransactionListQuery $query): QueryBuilder
    {
        $builder = $this->createQueryBuilder('t')
            ->where('t.account = :account')
            ->setParameter('account', $account->value);

        if (null !== $query->currency()) {
            $builder->andWhere('t.currency = :currency')->setParameter('currency', $query->currency());
        }

        if (null !== $query->direction()) {
            $builder->andWhere('t.direction = :direction')->setParameter('direction', $query->direction()->value);
        }

        return $builder;
    }
}
