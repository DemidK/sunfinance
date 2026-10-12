<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class AccountBalance
{
    public function __construct(
        public string $currency,
        public string $debit,
        public string $credit,
    ) {
    }

    public function balance(): string
    {
        return bcsub($this->debit, $this->credit, 2);
    }

    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'balance' => $this->balance(),
        ];
    }
}
