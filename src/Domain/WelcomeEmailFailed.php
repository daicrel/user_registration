<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;
use Throwable;

final class WelcomeEmailFailed extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('No se pudo simular el correo de bienvenida.', 0, $previous);
    }
}
