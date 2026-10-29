<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\WelcomeEmailSender;
use App\Domain\User;

final class FakeWelcomeEmailSender implements WelcomeEmailSender
{
    public const SUBJECT = '¡Bienvenido a nuestra plataforma!';
    public const DISCLAIMER = 'Simulación local: este mensaje no se ha enviado por correo electrónico.';

    /** @var list<array{to: string, name: string, subject: string, body: string, disclaimer: string}> */
    private array $requested = [];

    public function send(User $user): void
    {
        $name = $user->name();
        $to = $user->email();

        $body = implode("\n\n", [
            "Hola, {$name}:",
            '¡Te damos la bienvenida a nuestra plataforma! Tu registro se ha completado correctamente.',
            'Gracias por unirte a nosotros.',
            'El equipo',
        ]);

        $this->requested[] = [
            'to' => $to,
            'name' => $name,
            'subject' => self::SUBJECT,
            'body' => $body,
            'disclaimer' => self::DISCLAIMER,
        ];
    }

    /**
     * @return list<array{to: string, name: string, subject: string, body: string, disclaimer: string}>
     */
    public function requested(): array
    {
        return $this->requested;
    }

    /**
     * @return array{to: string, name: string, subject: string, body: string, disclaimer: string}|null
     */
    public function lastEmail(): ?array
    {
        if ($this->requested === []) {
            return null;
        }

        return $this->requested[array_key_last($this->requested)];
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
        $email = $this->lastEmail();
        if ($email === null) {
            return null;
        }

        return sprintf(
            "Para: %s\nAsunto: %s\n\n%s\n\n%s",
            $email['to'],
            $email['subject'],
            $email['body'],
            $email['disclaimer']
        );
    }
}
