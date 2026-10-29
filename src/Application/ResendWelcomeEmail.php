<?php

declare(strict_types=1);

namespace App\Application;

use App\Contracts\UserRepository;
use App\Contracts\WelcomeEmailSender;
use App\Domain\User;
use App\Domain\UserNotFound;
use App\Domain\WelcomeEmailFailed;
use Throwable;

final class ResendWelcomeEmail
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly WelcomeEmailSender $welcomeEmailSender
    ) {
    }

    public function execute(string $userId): User
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new UserNotFound($userId);
        }

        try {
            $this->welcomeEmailSender->send($user);
        } catch (Throwable $e) {
            throw new WelcomeEmailFailed($e);
        }

        return $user;
    }
}
