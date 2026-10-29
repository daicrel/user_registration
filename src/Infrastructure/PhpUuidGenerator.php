<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\UuidGenerator;
use App\Support\Uuid;

final class PhpUuidGenerator implements UuidGenerator
{
    public function v4(): string
    {
        return Uuid::v4();
    }
}
