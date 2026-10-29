<?php

declare(strict_types=1);

namespace App\Http;

final class CsrfToken
{
    private const SESSION_KEY = '_csrf_token';

    public function get(): string
    {
        $token = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    public function isValid(mixed $provided): bool
    {
        if (!is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals($this->get(), $provided);
    }
}
