<?php

declare(strict_types=1);

namespace App\Application;

use App\Contracts\UserRepository;
use App\Domain\UserNotFound;

final class DeleteUser
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function execute(string $userId): void
    {
        if ($this->users->findById($userId) === null) {
            throw new UserNotFound($userId);
        }

        $this->users->deleteById($userId);
    }
}
