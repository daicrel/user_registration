<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\WelcomeEmailSender;
use App\Domain\User;

final class FakeWelcomeEmailSender implements WelcomeEmailSender
{
    /** @var list<array{to: string, name: string}> */
    private array $requested = [];

    public function send(User $user): void
    {
        $this->requested[] = [
            'to' => $user->email(),
            'name' => $user->name(),
        ];
    }

    /**
     * @return list<array{to: string, name: string}>
     */
    public function requested(): array
    {
        return $this->requested;
    }

    public function wasRequestedFor(string $email): bool
    {
        foreach ($this->requested as $request) {
            if (strcasecmp($request['to'], $email) === 0) {
                return true;
            }
        }

        return false;
    }

    public function lastRequestDescription(): ?string
    {
        if ($this->requested === []) {
            return null;
        }

        $last = $this->requested[array_key_last($this->requested)];

        return sprintf(
            'Se solicitó un envío simulado de bienvenida para %s <%s>. No se usó SMTP y no implica entrega en una bandeja.',
            $last['name'],
            $last['to']
        );
    }
}
