<?php

declare(strict_types=1);

namespace App\Enum;

enum Account: string
{
    case User = 'user_account';
    case SystemCash = 'system_cash_account';
    case Fee = 'fee_account';
}
