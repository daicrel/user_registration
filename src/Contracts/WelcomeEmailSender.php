<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Domain\User;

interface WelcomeEmailSender
{
    public function send(User $user): void;
}
