<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Application\DeleteUser;
use App\Application\ListUsers;
use App\Application\RegisterUser;
use App\Application\ResendWelcomeEmail;
use App\Contracts\UserRepository;
use App\Contracts\WelcomeEmailPreview;
use App\Domain\Email;
use App\Domain\EmailAlreadyRegistered;
use App\Domain\InvalidUserData;
use App\Domain\UserNotFound;
use App\Domain\UserPersistenceFailed;
use App\Domain\WelcomeEmailFailed;
use App\Infrastructure\FakeWelcomeEmailSender;
use App\Infrastructure\PdoUserRepository;
use App\Infrastructure\PhpUuidGenerator;

if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_cp_set')) {
    sapi_windows_cp_set(65001);
}

$pdo = new PDO('sqlite::memory:');
PdoUserRepository::createSqliteSchema($pdo);

$users = new PdoUserRepository($pdo);
$emails = new FakeWelcomeEmailSender();
$uuids = new PhpUuidGenerator();

$register = new RegisterUser($users, $emails, $uuids);
$listUsers = new ListUsers($users);
$resendWelcome = new ResendWelcomeEmail($users, $emails);
$deleteUser = new DeleteUser($users);

function leer(string $etiqueta): string
{
    echo $etiqueta;
    $linea = fgets(STDIN);
    if ($linea === false) {
        echo "\n";
        exit(0);
    }

    return trim($linea);
}

function stdinEsTerminal(): bool
{
    return function_exists('stream_isatty') && @stream_isatty(STDIN);
}

function leerOculto(string $etiqueta): string
{
    echo $etiqueta;

    if (!stdinEsTerminal()) {
        $linea = fgets(STDIN);
        if ($linea === false) {
            echo "\n";
            exit(0);
        }

        return trim($linea);
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $comando = 'powershell -NoProfile -Command ' . escapeshellarg(
            '$p = Read-Host -AsSecureString; $b = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($p); try { [Runtime.InteropServices.Marshal]::PtrToStringAuto($b) } finally { if ($b -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($b) } }'
        );
        $salida = @shell_exec($comando);
        echo "\n";
        if (!is_string($salida)) {
            aviso('No se pudo ocultar la contraseña. El texto puede verse al escribirla.');

            return leer('');
        }

        return rtrim($salida, "\r\n");
    }

    $oculto = @shell_exec('stty -echo 2>/dev/null');
    if ($oculto === null) {
        aviso('No se pudo ocultar la contraseña en esta terminal. El texto puede verse al escribirla.');
        $linea = fgets(STDIN);
        echo "\n";
        if ($linea === false) {
            exit(0);
        }

        return trim($linea);
    }

    $linea = fgets(STDIN);
    @shell_exec('stty echo 2>/dev/null');
    echo "\n";
    if ($linea === false) {
        exit(0);
    }

    return trim($linea);
}

function pausar(): void
{
    leer("\nPulsa Enter para volver al menú...");
}

function ok(string $mensaje): void
{
    echo "[OK] {$mensaje}\n";
}

function error(string $mensaje): void
{
    echo "[ERROR] {$mensaje}\n";
}

function aviso(string $mensaje): void
{
    echo "[AVISO] {$mensaje}\n";
}

function linea(): void
{
    echo str_repeat('-', 46) . "\n";
}

function titulo(string $texto): void
{
    echo "\n";
    linea();
    echo "  {$texto}\n";
    linea();
}

function marco(string ...$lineas): void
{
    $ancho = 42;
    echo '+' . str_repeat('-', $ancho + 2) . "+\n";
    foreach ($lineas as $linea) {
        $hueco = $ancho - mb_strlen($linea);
        $izq = intdiv(max(0, $hueco), 2);
        $der = max(0, $hueco) - $izq;
        echo '| ' . str_repeat(' ', $izq) . $linea . str_repeat(' ', $der) . " |\n";
    }
    echo '+' . str_repeat('-', $ancho + 2) . "+\n";
}

function mostrarMenu(): void
{
    echo "\n";
    marco('REGISTRO DE USUARIOS', 'DEMO POR CONSOLA');
    echo <<<MENU

  1. Registrar nuevo usuario
  2. Consultar usuarios registrados
  3. Reenviar correo de bienvenida
  4. Eliminar usuario
  0. Salir

MENU;
}

/**
 * @param list<array{id: string, name: string, email: string}> $listed
 */
function imprimirTabla(array $listed): void
{
    if ($listed === []) {
        aviso('Aún no hay usuarios registrados.');

        return;
    }

    $idW = 36;
    $nameW = 22;
    $emailW = 28;

    foreach ($listed as $row) {
        $nameW = max($nameW, mb_strlen($row['name']));
        $emailW = max($emailW, mb_strlen($row['email']));
    }

    $fmt = sprintf("%%-%ds  %%-%ds  %%-%ds\n", $idW, $nameW, $emailW);
    printf($fmt, 'ID', 'Nombre', 'Email');
    echo str_repeat('-', $idW + $nameW + $emailW + 4) . "\n";

    foreach ($listed as $row) {
        printf($fmt, $row['id'], $row['name'], $row['email']);
    }
}

/**
 * @param list<array{id: string, name: string, email: string}> $listed
 * @return array{id: string, name: string, email: string}|null
 */
function elegirUsuario(array $listed, string $accion): ?array
{
    if ($listed === []) {
        aviso('No hay usuarios para ' . $accion . '.');

        return null;
    }

    echo "Usuarios disponibles:\n";
    foreach ($listed as $i => $row) {
        $n = $i + 1;
        echo "  {$n}. {$row['name']} <{$row['email']}>\n";
    }
    echo "  0. Cancelar\n";

    $maximo = count($listed);
    while (true) {
        $eleccion = leer("\nNúmero: ");
        if ($eleccion === '0' || $eleccion === '') {
            aviso('Operación cancelada.');

            return null;
        }

        if (!ctype_digit($eleccion)) {
            error('Escribe un número de la lista, o 0 / Enter para cancelar.');
            continue;
        }

        $indice = (int) $eleccion - 1;
        if (!isset($listed[$indice])) {
            error("No existe ese número. Elige entre 1 y {$maximo}, o 0 para cancelar.");
            continue;
        }

        return $listed[$indice];
    }
}

function mostrarCorreoSimulado(WelcomeEmailPreview $preview): void
{
    $email = $preview->lastEmail();
    if ($email === null) {
        aviso('El usuario se guardó, pero no se pudo simular el correo de bienvenida.');

        return;
    }

    echo "\nCorreo de bienvenida (simulado, no se ha enviado por SMTP)\n";
    echo "  Para:    {$email['to']}\n";
    echo "  Asunto:  {$email['subject']}\n";
    echo "  Cuerpo:\n";
    foreach (preg_split("/\n/", $email['body']) as $linea) {
        echo "    {$linea}\n";
    }
    echo "  {$email['disclaimer']}\n";
}

function pedirEmail(UserRepository $users): ?string
{
    while (true) {
        $email = leer('Email: ');
        if ($email === '') {
            aviso('Registro cancelado.');

            return null;
        }

        try {
            $normalizado = Email::parse($email);
            if ($users->findByEmail($normalizado) !== null) {
                error((new EmailAlreadyRegistered($normalizado))->getMessage());
                echo "Vuelve a escribirlo, o deja el email vacío para cancelar.\n";
                continue;
            }

            return $email;
        } catch (InvalidUserData | UserPersistenceFailed $e) {
            error($e->getMessage());
            echo "Vuelve a escribirlo, o deja el email vacío para cancelar.\n";
        }
    }
}

function registrarUsuario(
    RegisterUser $register,
    WelcomeEmailPreview $preview,
    string $name,
    string $email,
    string $password
): bool {
    try {
        $user = $register->execute($name, $email, $password);
        ok('Usuario persistido (UUID v4, contraseña con password_hash).');
        echo "  ID:    {$user->id()}\n";
        echo "  Nombre: {$user->name()}\n";
        echo "  Email:  {$user->email()}\n";
        mostrarCorreoSimulado($preview);

        return true;
    } catch (InvalidUserData | EmailAlreadyRegistered | UserPersistenceFailed $e) {
        error($e->getMessage());

        return false;
    }
}

if ($argc >= 4) {
    registrarUsuario($register, $emails, $argv[1], $argv[2], $argv[3]);
    exit(0);
}

while (true) {
    mostrarMenu();
    $opcion = leer('Selecciona una opción: ');

    if ($opcion === '0') {
        echo "Hasta luego.\n";
        break;
    }

    if ($opcion === '1') {
        titulo('Registrar nuevo usuario');
        $name = leer('Nombre completo: ');
        $email = pedirEmail($users);
        if ($email !== null) {
            $password = leerOculto('Contraseña: ');
            registrarUsuario($register, $emails, $name, $email, $password);
        }
        pausar();
        continue;
    }

    if ($opcion === '2') {
        titulo('Usuarios registrados');
        imprimirTabla($listUsers->execute());
        pausar();
        continue;
    }

    if ($opcion === '3') {
        titulo('Reenviar correo de bienvenida');
        $elegido = elegirUsuario($listUsers->execute(), 'reenviar correo');
        if ($elegido !== null) {
            try {
                $resendWelcome->execute($elegido['id']);
                ok('Reenvío simulado.');
                mostrarCorreoSimulado($emails);
            } catch (UserNotFound | UserPersistenceFailed | WelcomeEmailFailed $e) {
                error($e->getMessage());
            }
        }
        pausar();
        continue;
    }

    if ($opcion === '4') {
        titulo('Eliminar usuario');
        $elegido = elegirUsuario($listUsers->execute(), 'eliminar');
        if ($elegido !== null) {
            $confirma = leer("¿Eliminar a {$elegido['name']} <{$elegido['email']}>? (s/N): ");
            if (strcasecmp($confirma, 's') !== 0 && strcasecmp($confirma, 'si') !== 0) {
                aviso('Eliminación cancelada.');
            } else {
                try {
                    $deleteUser->execute($elegido['id']);
                    ok('Usuario eliminado. El email queda libre para un nuevo registro.');
                } catch (UserNotFound | UserPersistenceFailed $e) {
                    error($e->getMessage());
                }
            }
        }
        pausar();
        continue;
    }

    error('Opción no válida. Elige 0-4.');
    pausar();
}
