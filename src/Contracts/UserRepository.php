<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\User;

interface UserRepository
{
    public function save(User $user): void;

    public function findByEmail(string $email): ?User;
}
