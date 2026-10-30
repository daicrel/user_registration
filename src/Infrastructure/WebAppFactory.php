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

final class WebAppFactory
{
    public static function create(string $projectRoot): WebApp
    {
        $users = PdoUserRepository::fromProjectSqlite($projectRoot);
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
