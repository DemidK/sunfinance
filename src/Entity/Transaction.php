<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Account;
use App\Enum\Direction;
use App\Repository\TransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: 'transactions')]
#[ORM\Index(name: 'idx_transactions_event_id', columns: ['event_id'])]
#[ORM\UniqueConstraint(name: 'uniq_transactions_event_direction', columns: ['event_id', 'direction'])]
#[ORM\Index(name: 'idx_transactions_account', columns: ['account'])]
class Transaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'event_id', length: 255)]
    private string $eventId;

    #[ORM\Column(length: 64, enumType: Account::class)]
    private Account $account;

    #[ORM\Column(type: Types::DECIMAL, precision: 18, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 6, enumType: Direction::class)]
    private Direction $direction;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $eventId,
        Account $account,
        string $amount,
        string $currency,
        Direction $direction,
    ) {
        $this->eventId = $eventId;
        $this->account = $account;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->direction = $direction;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getDirection(): Direction
    {
        return $this->direction;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
