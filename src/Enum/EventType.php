<?php

declare(strict_types=1);

namespace App\Enum;

enum EventType: string
{
    case PaymentReceived = 'payment_received';
    case PaymentSent = 'payment_sent';
    case FeeCharged = 'fee_charged';

    public function debitAccount(): Account
    {
        return match ($this) {
            self::PaymentReceived, self::FeeCharged => Account::User,
            self::PaymentSent => Account::SystemCash,
        };
    }

    public function creditAccount(): Account
    {
        return match ($this) {
            self::PaymentReceived => Account::SystemCash,
            self::PaymentSent => Account::User,
            self::FeeCharged => Account::Fee,
        };
    }

    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
