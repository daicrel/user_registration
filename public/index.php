<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Application\RegisterUser;
use App\Application\ResendWelcomeEmail;
use App\Domain\EmailAlreadyRegistered;
use App\Domain\InvalidUserData;
use App\Domain\User;
use App\Domain\UserNotFound;
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
$resendWelcome = new ResendWelcomeEmail($users, $emails);

$error = null;
$success = null;
$welcomeEmail = null;
$name = '';
$email = '';
$page = (string) ($_GET['page'] ?? 'register');
$action = (string) ($_POST['action'] ?? 'register');
$redirect = strtok((string) $_SERVER['REQUEST_URI'], '?') ?: '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'resend') {
    try {
        $resendWelcome->execute((string) ($_POST['user_id'] ?? ''));
        $_SESSION['flash_welcome_email'] = $emails->lastEmail();
        header('Location: ' . $redirect . '?page=users');
        exit;
    } catch (UserNotFound | UserPersistenceFailed $e) {
        $error = $e->getMessage();
        $page = 'users';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'register') {
    $name = (string) ($_POST['name'] ?? '');
    $email = (string) ($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    try {
        $user = $register->execute($name, $email, $password);
        $_SESSION['flash_success'] = [
            'id' => $user->id(),
            'name' => $user->name(),
            'email' => $user->email(),
        ];
        $_SESSION['flash_welcome_email'] = $emails->lastEmail();
        header('Location: ' . $redirect);
        exit;
    } catch (InvalidUserData | EmailAlreadyRegistered | UserPersistenceFailed $e) {
        $error = $e->getMessage();
        $page = 'register';
    }
}

if (isset($_SESSION['flash_success']) && is_array($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if (isset($_SESSION['flash_welcome_email']) && is_array($_SESSION['flash_welcome_email'])) {
    $welcomeEmail = $_SESSION['flash_welcome_email'];
    unset($_SESSION['flash_welcome_email']);
}

$listedUsers = [];
if ($page === 'users') {
    $listedUsers = array_map(
        static fn (User $user): array => [
            'id' => $user->id(),
            'name' => $user->name(),
            'email' => $user->email(),
        ],
        $users->all()
    );
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

require __DIR__ . '/views/home.php';
