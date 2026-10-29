<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;
use Throwable;

final class UserPersistenceFailed extends RuntimeException
{
    public function __construct(
        ?Throwable $previous = null,
        string $message = 'No se pudo completar la operación.'
    ) {
        parent::__construct($message, 0, $previous);
    }
}
