<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class DateTimeStringValidator extends ConstraintValidator
{
    private const FORMATS = [
        \DATE_ATOM,
        'Y-m-d\TH:i:s.uP',
        'Y-m-d\TH:i:s.vP',
        'Y-m-d H:i:s',
        'Y-m-d H:i:sP',
    ];

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof DateTimeString) {
            throw new UnexpectedTypeException($constraint, DateTimeString::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!is_string($value) || null === self::parse($value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }

    public static function parse(string $value): ?\DateTimeImmutable
    {
        foreach (self::FORMATS as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = \DateTimeImmutable::getLastErrors();

            if (false !== $date && (false === $errors || (0 === $errors['warning_count'] && 0 === $errors['error_count']))) {
                return $date;
            }
        }

        return null;
    }
}
