<?php

declare(strict_types=1);

namespace App\Application;

use App\Contracts\UserRepository;
use App\Domain\Email;
use App\Domain\InvalidUserData;

final class CheckEmailAvailability
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * @return bool|null true disponible, false ocupado, null formato vacío o inválido
     */
    public function execute(string $email): ?bool
    {
        try {
            $normalized = Email::parse($email);
        } catch (InvalidUserData) {
            return null;
        }

        return $this->users->findByEmail($normalized) === null;
    }
}
