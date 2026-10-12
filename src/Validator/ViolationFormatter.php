<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\ConstraintViolationListInterface;

final class ViolationFormatter
{
    public static function toArray(ConstraintViolationListInterface $violations): array
    {
        $errors = [];
        foreach ($violations as $violation) {
            $errors[] = [
                'field' => self::fieldName($violation->getPropertyPath()),
                'message' => $violation->getMessage(),
            ];
        }

        return $errors;
    }

    private static function fieldName(string $propertyPath): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $propertyPath) ?? $propertyPath);
    }
}
