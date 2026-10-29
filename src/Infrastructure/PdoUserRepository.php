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
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL
            )'
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

            throw new UserPersistenceFailed();
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
        } catch (PDOException) {
            throw new UserPersistenceFailed();
        }

        if ($row === false) {
            return null;
        }

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
        $message = $e->getMessage();

        return $sqlState === '23000'
            && (
                str_contains($message, 'UNIQUE constraint failed: users.email')
                || str_contains($message, 'Duplicate entry')
            );
    }
}
