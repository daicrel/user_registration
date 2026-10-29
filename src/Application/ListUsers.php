<?php

declare(strict_types=1);

namespace App\Application;

use App\Contracts\UserRepository;

final class ListUsers
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * @return list<array{id: string, name: string, email: string}>
     */
    public function execute(): array
    {
        $listed = [];

        foreach ($this->users->all() as $user) {
            $listed[] = [
                'id' => $user->id(),
                'name' => $user->name(),
                'email' => $user->email(),
            ];
        }

        return $listed;
    }
}
