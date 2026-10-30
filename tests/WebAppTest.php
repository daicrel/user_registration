<?php

declare(strict_types=1);

namespace Tests;

use App\Application\CheckEmailAvailability;
use App\Application\DeleteUser;
use App\Application\ListUsers;
use App\Application\RegisterUser;
use App\Application\ResendWelcomeEmail;
use App\Contracts\WelcomeEmailPreview;
use App\Contracts\WelcomeEmailSender;
use App\Contracts\UserRepository;
use App\Domain\User;
use App\Domain\UserPersistenceFailed;
use App\Http\CsrfToken;
use App\Http\SessionFlash;
use App\Http\View;
use App\Http\WebApp;
use App\Infrastructure\FakeWelcomeEmailSender;
use App\Infrastructure\InMemoryUserRepository;
use App\Infrastructure\PhpUuidGenerator;
use PHPUnit\Framework\TestCase;

final class WebAppTest extends TestCase
{
    private InMemoryUserRepository $users;
    private FakeWelcomeEmailSender $emails;

    protected function setUp(): void
    {
        $_GET = [];
        $_POST = [];
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $this->users = new InMemoryUserRepository();
        $this->emails = new FakeWelcomeEmailSender();
    }

    public function testPostWithoutCsrfDoesNotRegister(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'action' => 'register',
            'name' => 'Juan Carlos García López',
            'email' => 'juan.garcia@example.com',
            'password' => 'S3gura!#@$',
        ];

        $result = $this->app()->handle();

        $this->assertSame('La solicitud no es válida. Vuelve a intentarlo.', $result['data']['error']);
        $this->assertNull($this->users->findByEmail('juan.garcia@example.com'));
        $this->assertSame([], $this->emails->requested());
    }

    public function testPostWithValidCsrfRegistersUser(): void
    {
        $token = $this->app()->handle()['data']['csrfToken'];

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            '_csrf' => $token,
            'action' => 'register',
            'name' => 'Juan Carlos García López',
            'email' => 'juan.garcia@example.com',
            'password' => 'S3gura!#@$',
        ];

        $result = $this->app()->handle();

        $this->assertArrayHasKey('location', $result);
        $this->assertNotNull($this->users->findByEmail('juan.garcia@example.com'));
    }

    public function testResendUsesStoredUserNotPostedEmail(): void
    {
        $this->registerStoredUser();
        $user = $this->users->findByEmail('juan.garcia@example.com');
        $this->assertNotNull($user);

        $token = $this->app()->handle()['data']['csrfToken'];
        $_GET['page'] = 'users';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            '_csrf' => $token,
            'action' => 'resend',
            'user_id' => $user->id(),
            'email' => 'atacante@evil.com',
            'name' => 'Nombre Falso Pérez',
        ];

        $result = $this->app()->handle();

        $this->assertSame('/?page=users#welcome-email', $result['location']);
        $last = $this->emails->lastEmail();
        $this->assertNotNull($last);
        $this->assertSame('juan.garcia@example.com', $last['to']);
        $this->assertSame('Juan Carlos García López', $last['name']);
        $this->assertStringContainsString('Hola, Juan Carlos García López:', $last['body']);
        $this->assertSame('Se simuló el reenvío del correo de bienvenida.', $_SESSION['notice'] ?? null);
        $this->assertIsArray($_SESSION['welcome_email'] ?? null);
        $this->assertSame('juan.garcia@example.com', $_SESSION['welcome_email']['to']);
    }

    public function testResendShowsSafeErrorWhenSendFailsAndKeepsUser(): void
    {
        $this->registerStoredUser();
        $user = $this->users->findByEmail('juan.garcia@example.com');
        $this->assertNotNull($user);

        $failingMail = new class implements WelcomeEmailSender, WelcomeEmailPreview {
            public function send(User $user): void
            {
                throw new \RuntimeException('SMTP caido');
            }

            public function lastEmail(): ?array
            {
                return null;
            }
        };

        $token = $this->appWith($failingMail)->handle()['data']['csrfToken'];
        $_GET['page'] = 'users';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            '_csrf' => $token,
            'action' => 'resend',
            'user_id' => $user->id(),
        ];

        $result = $this->appWith($failingMail)->handle();

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('users', $result['data']['page']);
        $this->assertSame('No se pudo simular el correo de bienvenida.', $result['data']['error']);
        $this->assertStringNotContainsString('SMTP', (string) $result['data']['error']);
        $this->assertNull($result['data']['welcomeEmail']);
        $this->assertNotNull($this->users->findById($user->id()));
        $this->assertSame('Juan Carlos García López', $this->users->findById($user->id())?->name());
        $this->assertSame('juan.garcia@example.com', $this->users->findById($user->id())?->email());
    }

    public function testDeleteRemovesUserAndLeavesNoRow(): void
    {
        $this->registerStoredUser();
        $user = $this->users->findByEmail('juan.garcia@example.com');
        $this->assertNotNull($user);

        $token = $this->app()->handle()['data']['csrfToken'];
        $_GET['page'] = 'users';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            '_csrf' => $token,
            'action' => 'delete',
            'user_id' => $user->id(),
        ];

        $result = $this->app()->handle();

        $this->assertSame('/?page=users', $result['location']);
        $this->assertNull($this->users->findById($user->id()));
        $this->assertNull($this->users->findByEmail('juan.garcia@example.com'));
        $this->assertSame([], $this->users->all());
    }

    public function testPrgWelcomeEmailComesFromSessionAfterNewAppInstance(): void
    {
        $token = $this->app()->handle()['data']['csrfToken'];

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            '_csrf' => $token,
            'action' => 'register',
            'name' => 'Juan Carlos García López',
            'email' => 'juan.garcia@example.com',
            'password' => 'S3gura!#@$',
        ];

        $post = $this->app()->handle();

        $this->assertArrayHasKey('location', $post);
        $this->assertNotNull($this->users->findByEmail('juan.garcia@example.com'));
        $this->assertIsArray($_SESSION['welcome_email'] ?? null);
        $this->assertSame('juan.garcia@example.com', $_SESSION['welcome_email']['to']);

        $freshEmails = new FakeWelcomeEmailSender();
        $this->assertNull($freshEmails->lastEmail());

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        $_GET = [];
        $get = $this->appWith($freshEmails)->handle();

        $this->assertNull($freshEmails->lastEmail());
        $this->assertIsArray($get['data']['welcomeEmail']);
        $this->assertSame('juan.garcia@example.com', $get['data']['welcomeEmail']['to']);
        $this->assertSame('Juan Carlos García López', $get['data']['welcomeEmail']['name']);

        $again = $this->appWith(new FakeWelcomeEmailSender())->handle();
        $this->assertNull($again['data']['welcomeEmail']);
    }

    public function testRegisterStillSucceedsWhenWelcomeEmailFails(): void
    {
        $failingMail = new class implements WelcomeEmailSender, WelcomeEmailPreview {
            public function send(User $user): void
            {
                throw new \RuntimeException('SMTP caido');
            }

            public function lastEmail(): ?array
            {
                return null;
            }
        };

        $token = $this->appWith($failingMail)->handle()['data']['csrfToken'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            '_csrf' => $token,
            'action' => 'register',
            'name' => 'Juan Carlos García López',
            'email' => 'juan.garcia@example.com',
            'password' => 'S3gura!#@$',
        ];

        $post = $this->appWith($failingMail)->handle();

        $this->assertArrayHasKey('location', $post);
        $this->assertNotNull($this->users->findByEmail('juan.garcia@example.com'));
        $this->assertArrayNotHasKey('welcome_email', $_SESSION);
        $this->assertSame(
            'El usuario se ha registrado, pero no se pudo simular el correo de bienvenida.',
            $_SESSION['notice'] ?? null
        );
        $this->assertStringNotContainsString('S3gura!#@$', (string) ($_SESSION['notice'] ?? ''));
        $this->assertStringNotContainsString('SMTP', (string) ($_SESSION['notice'] ?? ''));

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        $get = $this->appWith($failingMail)->handle();

        $this->assertIsArray($get['data']['success']);
        $this->assertNull($get['data']['welcomeEmail']);
        $this->assertSame(
            'El usuario se ha registrado, pero no se pudo simular el correo de bienvenida.',
            $get['data']['notice']
        );
    }

    public function testDuplicateEmailShowsClearErrorAndDoesNotCreateSecondUser(): void
    {
        $this->registerStoredUser();
        $token = $this->app()->handle()['data']['csrfToken'];

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            '_csrf' => $token,
            'action' => 'register',
            'name' => 'María Elena Ruiz Soto',
            'email' => 'JUAN.GARCIA@example.com',
            'password' => 'S3gura!#@$',
        ];

        $result = $this->app()->handle();

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('Este correo electrónico ya está registrado.', $result['data']['error']);
        $this->assertStringNotContainsString('S3gura!#@$', (string) $result['data']['error']);
        $this->assertStringNotContainsString('$2y$', (string) $result['data']['error']);
        $this->assertCount(1, $this->users->all());
        $this->assertSame('Juan Carlos García López', $this->users->all()[0]->name());
    }

    public function testCheckEmailEndpointReportsAvailableAddress(): void
    {
        $_GET['action'] = 'check-email';
        $_GET['email'] = '  Pedro.Santos@Example.com  ';

        $result = $this->app()->handle();

        $this->assertSame(['json' => ['ok' => true, 'available' => true]], $result);
        $this->assertSame([], $this->users->all());
    }

    public function testCheckEmailEndpointReportsTakenAddressIgnoringCase(): void
    {
        $this->registerStoredUser();
        $_GET['action'] = 'check-email';
        $_GET['email'] = '  JUAN.GARCIA@EXAMPLE.COM  ';

        $result = $this->app()->handle();

        $this->assertSame(['json' => ['ok' => true, 'available' => false]], $result);
        $this->assertCount(1, $this->users->all());
    }

    public function testCheckEmailEndpointDoesNotMarkInvalidOrEmptyAsAvailable(): void
    {
        $_GET['action'] = 'check-email';
        $_GET['email'] = 'no-es-un-email';
        $invalid = $this->app()->handle();
        $this->assertSame(['json' => ['ok' => true]], $invalid);
        $this->assertArrayNotHasKey('available', $invalid['json']);

        $_GET['email'] = '';
        $empty = $this->app()->handle();
        $this->assertSame(['json' => ['ok' => true]], $empty);
        $this->assertArrayNotHasKey('available', $empty['json']);
    }

    public function testCheckEmailEndpointReportsErrorWithoutClaimingAvailable(): void
    {
        $failingUsers = new class implements UserRepository {
            public function save(User $user): void
            {
            }

            public function findByEmail(string $email): ?User
            {
                throw new UserPersistenceFailed();
            }

            public function findById(string $id): ?User
            {
                return null;
            }

            public function all(): array
            {
                return [];
            }

            public function deleteById(string $id): void
            {
            }
        };

        $app = new WebApp(
            new RegisterUser($failingUsers, $this->emails, new PhpUuidGenerator()),
            new ResendWelcomeEmail($failingUsers, $this->emails),
            new DeleteUser($failingUsers),
            new ListUsers($failingUsers),
            new CheckEmailAvailability($failingUsers),
            $this->emails,
            new SessionFlash(),
            new View(dirname(__DIR__) . '/public/views'),
            new CsrfToken()
        );

        $_GET['action'] = 'check-email';
        $_GET['email'] = 'juan.garcia@example.com';
        $result = $app->handle();

        $this->assertFalse($result['json']['ok']);
        $this->assertArrayNotHasKey('available', $result['json']);
        $this->assertSame(
            'No se pudo comprobar el correo electrónico. Inténtalo de nuevo.',
            $result['json']['error']
        );
    }

    public function testViewsEscapeDynamicHtml(): void
    {
        require_once dirname(__DIR__) . '/src/Http/html.php';

        $this->assertSame('&lt;script&gt;x&lt;/script&gt;', e('<script>x</script>'));
        $this->assertSame('a&quot;b', e('a"b'));

        $view = new View(dirname(__DIR__) . '/public/views');
        ob_start();
        $view->render('users.php', [
            'page' => 'users',
            'error' => '<b>error</b>',
            'listedUsers' => [[
                'id' => '11111111-1111-4111-8111-111111111111',
                'name' => '<script>alert(1)</script>',
                'email' => 'xss@example.com',
            ]],
            'csrfToken' => 'token',
        ]);
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString("confirm(", $html);
        $this->assertStringContainsString('id="confirm-delete"', $html);
        $this->assertStringContainsString('data-name="&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        $this->assertStringNotContainsString('<b>error</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;error&lt;/b&gt;', $html);
        $this->assertStringContainsString('name="_csrf"', $html);
    }

    private function registerStoredUser(): void
    {
        $register = new RegisterUser($this->users, $this->emails, new PhpUuidGenerator());
        $register->execute('Juan Carlos García López', 'juan.garcia@example.com', 'S3gura!#@$');
    }

    private function app(): WebApp
    {
        return $this->appWith($this->emails);
    }

    private function appWith(WelcomeEmailSender&WelcomeEmailPreview $emails): WebApp
    {
        return new WebApp(
            new RegisterUser($this->users, $emails, new PhpUuidGenerator()),
            new ResendWelcomeEmail($this->users, $emails),
            new DeleteUser($this->users),
            new ListUsers($this->users),
            new CheckEmailAvailability($this->users),
            $emails,
            new SessionFlash(),
            new View(dirname(__DIR__) . '/public/views'),
            new CsrfToken()
        );
    }
}
