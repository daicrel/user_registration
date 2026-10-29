<?php

declare(strict_types=1);

namespace App\Http;

use App\Application\DeleteUser;
use App\Application\ListUsers;
use App\Application\RegisterUser;
use App\Application\ResendWelcomeEmail;
use App\Contracts\WelcomeEmailPreview;
use App\Domain\EmailAlreadyRegistered;
use App\Domain\InvalidUserData;
use App\Domain\UserNotFound;
use App\Domain\UserPersistenceFailed;

final class WebApp
{
    public function __construct(
        private readonly RegisterUser $register,
        private readonly ResendWelcomeEmail $resendWelcome,
        private readonly DeleteUser $deleteUser,
        private readonly ListUsers $listUsers,
        private readonly WelcomeEmailPreview $welcomePreview,
        private readonly SessionFlash $flash,
        private readonly View $view,
        private readonly CsrfToken $csrf
    ) {
    }

    public function run(): void
    {
        $result = $this->handle();

        if (isset($result['location'])) {
            header('Location: ' . $result['location']);
            exit;
        }

        $this->view->render('home.php', $result['data']);
    }

    /**
     * @return array{location: string}|array{data: array<string, mixed>}
     */
    public function handle(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $error = null;
        $name = '';
        $email = '';
        $page = (string) ($_GET['page'] ?? 'register');
        $action = (string) ($_POST['action'] ?? 'register');
        $redirect = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->csrf->isValid($_POST['_csrf'] ?? null)) {
                $error = 'La solicitud no es válida. Vuelve a intentarlo.';
                $page = in_array($action, ['resend', 'delete'], true) ? 'users' : 'register';
            } elseif ($action === 'delete') {
                try {
                    $this->deleteUser->execute((string) ($_POST['user_id'] ?? ''));

                    return ['location' => $redirect . '?page=users'];
                } catch (UserNotFound | UserPersistenceFailed $e) {
                    $error = $e->getMessage();
                    $page = 'users';
                }
            } elseif ($action === 'resend') {
                try {
                    $this->resendWelcome->execute((string) ($_POST['user_id'] ?? ''));
                    $this->flash->set('welcome_email', $this->welcomePreview->lastEmail());
                    $this->flash->set('notice', 'Se simuló el reenvío del correo de bienvenida.');

                    return ['location' => $redirect . '?page=users#welcome-email'];
                } catch (UserNotFound | UserPersistenceFailed $e) {
                    $error = $e->getMessage();
                    $page = 'users';
                }
            } elseif ($action === 'register') {
                $name = (string) ($_POST['name'] ?? '');
                $email = (string) ($_POST['email'] ?? '');
                $password = (string) ($_POST['password'] ?? '');

                try {
                    $user = $this->register->execute($name, $email, $password);
                    $this->flash->set('success', [
                        'id' => $user->id(),
                        'name' => $user->name(),
                        'email' => $user->email(),
                    ]);
                    $this->flash->set('welcome_email', $this->welcomePreview->lastEmail());

                    return ['location' => $redirect];
                } catch (InvalidUserData | EmailAlreadyRegistered | UserPersistenceFailed $e) {
                    $error = $e->getMessage();
                    $page = 'register';
                }
            }
        }

        $success = $this->flash->pull('success');
        $welcomeEmail = $this->flash->pull('welcome_email');
        $notice = $this->flash->pull('notice');

        return [
            'data' => [
                'page' => $page,
                'error' => $error,
                'success' => is_array($success) ? $success : null,
                'notice' => is_string($notice) ? $notice : null,
                'welcomeEmail' => is_array($welcomeEmail) ? $welcomeEmail : null,
                'listedUsers' => $page === 'users' ? $this->listUsers->execute() : [],
                'name' => $name,
                'email' => $email,
                'csrfToken' => $this->csrf->get(),
            ],
        ];
    }
}
