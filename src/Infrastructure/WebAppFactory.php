<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Application\DeleteUser;
use App\Application\ListUsers;
use App\Application\RegisterUser;
use App\Application\ResendWelcomeEmail;
use App\Http\CsrfToken;
use App\Http\SessionFlash;
use App\Http\View;
use App\Http\WebApp;
use PDO;
use RuntimeException;

final class WebAppFactory
{
    public static function create(string $projectRoot): WebApp
    {
        $dataDir = $projectRoot . '/data';
        if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
            throw new RuntimeException('No se pudo crear el directorio data.');
        }

        $pdo = new PDO('sqlite:' . $dataDir . '/users.sqlite');
        PdoUserRepository::createSqliteSchema($pdo);

        $users = new PdoUserRepository($pdo);
        $emails = new FakeWelcomeEmailSender();
        $uuids = new PhpUuidGenerator();

        return new WebApp(
            new RegisterUser($users, $emails, $uuids),
            new ResendWelcomeEmail($users, $emails),
            new DeleteUser($users),
            new ListUsers($users),
            $emails,
            new SessionFlash(),
            new View($projectRoot . '/public/views'),
            new CsrfToken()
        );
    }
}
