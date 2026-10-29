<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Application\RegisterUser;
use App\Infrastructure\PhpUuidGenerator;
use App\Domain\Email;
use App\Domain\EmailAlreadyRegistered;
use App\Domain\InvalidUserData;
use App\Domain\UserPersistenceFailed;
use App\Infrastructure\FakeWelcomeEmailSender;
use App\Infrastructure\PdoUserRepository;

$pdo = new PDO('sqlite::memory:');
PdoUserRepository::createSqliteSchema($pdo);

$users = new PdoUserRepository($pdo);
$emails = new FakeWelcomeEmailSender();
$register = new RegisterUser($users, $emails, new PhpUuidGenerator());

function leer(string $etiqueta): string
{
    echo $etiqueta;
    $linea = fgets(STDIN);

    return $linea === false ? '' : trim($linea);
}

function registrar(RegisterUser $register, FakeWelcomeEmailSender $emails, string $name, string $email, string $password): void
{
    try {
        $user = $register->execute($name, $email, $password);

        echo "Usuario registrado\n";
        echo "  id:    {$user->id()}\n";
        echo "  name:  {$user->name()}\n";
        echo "  email: {$user->email()}\n";
        $welcome = $emails->lastRequestDescription();
        echo $welcome === null
            ? "  Usuario guardado. El envío de bienvenida no se completó; se puede reintentar.\n"
            : "\nCorreo de bienvenida (simulado)\n{$welcome}\n";
    } catch (InvalidUserData | EmailAlreadyRegistered | UserPersistenceFailed $e) {
        echo $e->getMessage() . "\n";
    }
}

if ($argc >= 4) {
    registrar($register, $emails, $argv[1], $argv[2], $argv[3]);
    exit(0);
}

echo "Registro de usuarios. Deja el nombre vacío para salir.\n\n";

while (true) {
    $name = leer('Nombre completo: ');
    if ($name === '') {
        echo "Fin.\n";
        break;
    }

    $email = leer('Email: ');

    try {
        $email = Email::parse($email);

        if ($users->findByEmail($email) !== null) {
            throw new EmailAlreadyRegistered($email);
        }
    } catch (InvalidUserData | EmailAlreadyRegistered | UserPersistenceFailed $e) {
        echo $e->getMessage() . "\n\n";
        continue;
    }

    $password = leer('Contraseña: ');

    registrar($register, $emails, $name, $email, $password);
    echo "\n";
}
