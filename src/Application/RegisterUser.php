<?php

declare(strict_types=1);

namespace App\Application;

use App\Contracts\UserRepository;
use App\Contracts\WelcomeEmailSender;
use App\Domain\EmailAlreadyRegistered;
use App\Domain\User;
use App\Support\Uuid;

final class RegisterUser
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly WelcomeEmailSender $welcomeEmailSender
    ) {
    }

    public function execute(string $name, string $email, string $password): User
    {
        $user = User::register(Uuid::v4(), $name, $email, $password);

        if ($this->users->findByEmail($user->email()) !== null) {
            throw new EmailAlreadyRegistered($user->email());
        }

        // La comprobación anterior no cubre dos peticiones a la vez.
        // save() debe chocar con UNIQUE y traducir el conflicto.
        $this->users->save($user);
        $this->welcomeEmailSender->send($user);

        return $user;
    }
}
