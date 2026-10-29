<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

final class UserPersistenceFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No se pudo completar el registro.');
    }
}
