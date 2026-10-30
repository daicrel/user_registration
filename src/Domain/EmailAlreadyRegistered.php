<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

final class EmailAlreadyRegistered extends RuntimeException
{
    public function __construct(string $email)
    {
        parent::__construct('Este correo electrónico ya está registrado.');
    }
}
