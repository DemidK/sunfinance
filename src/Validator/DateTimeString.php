<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class DateTimeString extends Constraint
{
    public string $message = 'This value is not a valid date/time.';
}
