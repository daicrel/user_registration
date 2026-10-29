<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Contracts\UserRepository;
use App\Domain\Email;
use App\Domain\EmailAlreadyRegistered;
use App\Domain\User;
use App\Domain\UserPersistenceFailed;
use PDO;
use PDOException;

final class PdoUserRepository implements UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public static function createSqliteSchema(PDO $pdo): void
    {
        $pdo->exec('PRAGMA encoding = "UTF-8"');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id CHAR(36) NOT NULL PRIMARY KEY,
                name TEXT NOT NULL,
                email TEXT NOT NULL COLLATE NOCASE,
                password_hash TEXT NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique ON users(email COLLATE NOCASE)'
        );
    }

    public function save(User $user): void
    {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO users (id, name, email, password_hash)
                 VALUES (:id, :name, :email, :password_hash)'
            );

            $statement->execute([
                ':id' => $user->id(),
                ':name' => $user->name(),
                ':email' => $user->email(),
                ':password_hash' => $user->passwordHash(),
            ]);
        } catch (PDOException $e) {
            if (self::isUniqueEmailViolation($e)) {
                throw new EmailAlreadyRegistered($user->email());
            }

            throw new UserPersistenceFailed($e, 'No se pudo guardar el usuario.');
        }
    }

    public function findByEmail(string $email): ?User
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, name, email, password_hash
                 FROM users
                 WHERE email = :email
                 LIMIT 1'
            );

            $statement->execute([
                ':email' => Email::normalize($email),
            ]);

            $row = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new UserPersistenceFailed($e);
        }

        if ($row === false) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findById(string $id): ?User
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT id, name, email, password_hash
                 FROM users
                 WHERE id = :id
                 LIMIT 1'
            );
            $statement->execute([':id' => $id]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new UserPersistenceFailed($e);
        }

        if ($row === false) {
            return null;
        }

        return self::hydrate($row);
    }

    public function all(): array
    {
        try {
            $statement = $this->pdo->query(
                'SELECT id, name, email, password_hash FROM users ORDER BY name COLLATE NOCASE'
            );
            $rows = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new UserPersistenceFailed($e);
        }

        return array_map(self::hydrate(...), $rows);
    }

    public function deleteById(string $id): void
    {
        try {
            $statement = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
            $statement->execute([':id' => $id]);
        } catch (PDOException $e) {
            throw new UserPersistenceFailed($e, 'No se pudo eliminar el usuario.');
        }
    }

    /**
     * @param array{id: string, name: string, email: string, password_hash: string} $row
     */
    private static function hydrate(array $row): User
    {
        return User::fromPersistence(
            $row['id'],
            $row['name'],
            $row['email'],
            $row['password_hash']
        );
    }

    private static function isUniqueEmailViolation(PDOException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        if ($sqlState !== '23000') {
            return false;
        }

        $message = strtolower($e->getMessage());

        if (str_contains($message, 'users.id') || str_contains($message, 'primary key')) {
            return false;
        }

        return str_contains($message, 'users.email')
            || str_contains($message, 'duplicate entry')
            || (str_contains($message, 'unique') && str_contains($message, 'email'));
    }
}
