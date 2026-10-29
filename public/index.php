<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Application\RegisterUser;
use App\Domain\EmailAlreadyRegistered;
use App\Domain\InvalidUserData;
use App\Domain\UserPersistenceFailed;
use App\Infrastructure\FakeWelcomeEmailSender;
use App\Infrastructure\PdoUserRepository;

session_start();

$root = dirname(__DIR__);
$dataDir = $root . '/data';
if (!is_dir($dataDir) && !mkdir($dataDir, 0777, true) && !is_dir($dataDir)) {
    throw new RuntimeException('No se pudo crear el directorio data.');
}

$pdo = new PDO('sqlite:' . $dataDir . '/users.sqlite');
PdoUserRepository::createSqliteSchema($pdo);

$users = new PdoUserRepository($pdo);
$emails = new FakeWelcomeEmailSender();
$register = new RegisterUser($users, $emails);

$error = null;
$success = null;
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = (string) ($_POST['name'] ?? '');
    $email = (string) ($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    try {
        $user = $register->execute($name, $email, $password);
        $welcome = $emails->lastRequestDescription()
            ?? 'Usuario guardado. El envío de bienvenida no se completó; se puede reintentar.';

        $_SESSION['flash_success'] = [
            'id' => $user->id(),
            'name' => $user->name(),
            'email' => $user->email(),
            'welcome' => $welcome,
        ];
        header('Location: ' . strtok((string) $_SERVER['REQUEST_URI'], '?'));
        exit;
    } catch (InvalidUserData | EmailAlreadyRegistered | UserPersistenceFailed $e) {
        $error = $e->getMessage();
    }
}

if (isset($_SESSION['flash_success']) && is_array($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

require __DIR__ . '/views/register.html';
