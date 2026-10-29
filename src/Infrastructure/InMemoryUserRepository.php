<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\UserRepository;
use App\Domain\Email;
use App\Domain\EmailAlreadyRegistered;
use App\Domain\User;

final class InMemoryUserRepository implements UserRepository
{
    /** @var array<string, User> */
    private array $users = [];

    public function save(User $user): void
    {
        $key = Email::normalize($user->email());

        if (isset($this->users[$key])) {
            throw new EmailAlreadyRegistered($user->email());
        }

        $this->users[$key] = $user;
    }

    public function findByEmail(string $email): ?User
    {
        return $this->users[Email::normalize($email)] ?? null;
    }

    public function findById(string $id): ?User
    {
        foreach ($this->users as $user) {
            if ($user->id() === $id) {
                return $user;
            }
        }

        return null;
    }

    public function all(): array
    {
        return array_values($this->users);
    }

    public function deleteById(string $id): void
    {
        foreach ($this->users as $key => $user) {
            if ($user->id() === $id) {
                unset($this->users[$key]);

                return;
            }
        }
    }
}
