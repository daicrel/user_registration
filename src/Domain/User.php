<?php

declare(strict_types=1);

namespace App\Domain;

final class User
{
    private function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly string $email,
        private readonly string $passwordHash
    ) {
    }

    public static function register(
        string $id,
        string $name,
        string $email,
        string $plainPassword
    ): self {
        $name = trim($name);
        $email = Email::parse($email);

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
            throw new InvalidUserData('El identificador de usuario no es un UUID válido.');
        }

        if ($name === '') {
            throw new InvalidUserData('El nombre es obligatorio.');
        }

        if (strlen($plainPassword) < 8) {
            throw new InvalidUserData('La contraseña debe tener al menos 8 caracteres.');
        }

        return new self(
            $id,
            $name,
            $email,
            password_hash($plainPassword, PASSWORD_DEFAULT)
        );
    }

    public static function fromPersistence(
        string $id,
        string $name,
        string $email,
        string $passwordHash
    ): self {
        return new self($id, $name, $email, $passwordHash);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function verifyPassword(string $plainPassword): bool
    {
        return password_verify($plainPassword, $this->passwordHash);
    }
}
