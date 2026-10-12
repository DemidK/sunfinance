<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\Direction;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Validator\Constraints as Assert;

final class TransactionListQuery
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    #[Assert\Regex('/^\d+$/', message: 'This value should be a whole number.')]
    #[Assert\Range(min: 1, max: self::MAX_LIMIT)]
    public mixed $limit = self::DEFAULT_LIMIT;

    #[Assert\Regex('/^\d+$/', message: 'This value should be a whole number.')]
    #[Assert\PositiveOrZero]
    public mixed $offset = 0;

    #[Assert\Type('string')]
    #[Assert\Length(min: 3, max: 3)]
    public mixed $currency = null;

    #[Assert\Type('string')]
    #[Assert\Choice(callback: [Direction::class, 'values'], message: 'Unknown direction.')]
    public mixed $direction = null;

    public static function fromQuery(InputBag $query): self
    {
        $self = new self();
        $self->limit = $query->get('limit', self::DEFAULT_LIMIT);
        $self->offset = $query->get('offset', 0);
        $self->currency = $query->get('currency');
        $self->direction = $query->get('direction');

        return $self;
    }

    public function limit(): int
    {
        return (int) $this->limit;
    }

    public function offset(): int
    {
        return (int) $this->offset;
    }

    public function currency(): ?string
    {
        return null === $this->currency || '' === $this->currency ? null : strtoupper($this->currency);
    }

    public function direction(): ?Direction
    {
        return null === $this->direction || '' === $this->direction ? null : Direction::from($this->direction);
    }
}
