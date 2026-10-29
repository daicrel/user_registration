<?php

declare(strict_types=1);

namespace App\Domain;

final class Email
{
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function parse(string $email): string
    {
        $normalized = self::normalize($email);

        if (
            $normalized === ''
            || strlen($normalized) > 254
            || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidUserData('El email no es válido.');
        }

        return $normalized;
    }
}
