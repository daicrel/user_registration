<?php

declare(strict_types=1);

namespace App\Application;

use App\Contracts\UserRepository;
use App\Contracts\UuidGenerator;
use App\Contracts\WelcomeEmailSender;
use App\Domain\EmailAlreadyRegistered;
use App\Domain\User;
use Throwable;

final class RegisterUser
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly WelcomeEmailSender $welcomeEmailSender,
        private readonly UuidGenerator $uuids
    ) {
    }

    public function execute(string $name, string $email, string $password): User
    {
        $user = User::register($this->uuids->v4(), $name, $email, $password);

        if ($this->users->findByEmail($user->email()) !== null) {
            throw new EmailAlreadyRegistered($user->email());
        }

        $this->users->save($user);

        try {
            $this->welcomeEmailSender->send($user);
        } catch (Throwable) {
            // El usuario ya está persistido. El correo se puede reintentar fuera de este caso de uso.
        }

        return $user;
    }
}
