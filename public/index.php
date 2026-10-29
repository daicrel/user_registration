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

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registro de usuarios</title>
    <link rel="stylesheet" href="/css/register.css">
</head>
<body>
    <main class="card">
        <h1>Crear cuenta</h1>
        <p class="lead">Nombre completo, email y contraseña. El email debe ser único. La bienvenida es un envío simulado, no llega a una bandeja.</p>

        <?php if (is_array($success)): ?>
            <div class="msg ok" role="status">
                <p><strong>Usuario registrado</strong></p>
                <p>Nombre: <?= e((string) $success['name']) ?></p>
                <p>Email: <?= e((string) $success['email']) ?></p>
                <p>Id: <code><?= e((string) $success['id']) ?></code></p>
                <p><?= e((string) $success['welcome']) ?></p>
            </div>
        <?php endif; ?>

        <?php if (is_string($error)): ?>
            <div class="msg err" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="" autocomplete="on">
            <label for="name">Nombre completo</label>
            <input id="name" name="name" type="text" required value="<?= e($name) ?>" placeholder="José María">

            <label for="email">Email</label>
            <input id="email" name="email" type="email" required value="<?= e($email) ?>" placeholder="jose@example.com">

            <label for="password">Contraseña</label>
            <input id="password" name="password" type="password" required minlength="8" placeholder="Mínimo 8 caracteres">

            <button type="submit">Registrarme</button>
        </form>
        <p class="hint">Los datos se guardan fuera de public, en <code>data/users.sqlite</code>. La contraseña no se muestra en los errores.</p>
    </main>
</body>
</html>
