<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

final class UserNotFound extends RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct("No se encontró el usuario {$id}.");
    }
}
