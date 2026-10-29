<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;
use Throwable;

final class UserPersistenceFailed extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('No se pudo completar el registro.', 0, $previous);
    }
}
