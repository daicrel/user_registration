<?php

declare(strict_types=1);

namespace Tests;

use App\Application\DeleteUser;
use App\Application\ListUsers;
use App\Application\RegisterUser;
use App\Application\ResendWelcomeEmail;
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
            'name' => 'Ana Pérez',
            'email' => 'ana@example.com',
            'password' => 'secret123',
        ];

        $result = $this->app()->handle();

        $this->assertSame('La solicitud no es válida. Vuelve a intentarlo.', $result['data']['error']);
        $this->assertNull($this->users->findByEmail('ana@example.com'));
        $this->assertSame([], $this->emails->requested());
    }

    public function testPostWithValidCsrfRegistersUser(): void
    {
        $token = $this->app()->handle()['data']['csrfToken'];

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            '_csrf' => $token,
            'action' => 'register',
            'name' => 'Ana Pérez',
            'email' => 'ana@example.com',
            'password' => 'secret123',
        ];

        $result = $this->app()->handle();

        $this->assertArrayHasKey('location', $result);
        $this->assertNotNull($this->users->findByEmail('ana@example.com'));
    }

    public function testResendUsesStoredUserNotPostedEmail(): void
    {
        $this->registerStoredUser();
        $user = $this->users->findByEmail('ana@example.com');
        $this->assertNotNull($user);

        $token = $this->app()->handle()['data']['csrfToken'];
        $_GET['page'] = 'users';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            '_csrf' => $token,
            'action' => 'resend',
            'user_id' => $user->id(),
            'email' => 'atacante@evil.com',
            'name' => 'Nombre falso',
        ];

        $result = $this->app()->handle();

        $this->assertSame('/?page=users#welcome-email', $result['location']);
        $last = $this->emails->lastEmail();
        $this->assertNotNull($last);
        $this->assertSame('ana@example.com', $last['to']);
        $this->assertSame('Ana Pérez', $last['name']);
        $this->assertStringContainsString('Hola, Ana Pérez:', $last['body']);
    }

    public function testDeleteRemovesUserAndLeavesNoRow(): void
    {
        $this->registerStoredUser();
        $user = $this->users->findByEmail('ana@example.com');
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
        $this->assertNull($this->users->findByEmail('ana@example.com'));
        $this->assertSame([], $this->users->all());
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
        $this->assertStringNotContainsString('<b>error</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;error&lt;/b&gt;', $html);
        $this->assertStringContainsString('name="_csrf"', $html);
    }

    private function registerStoredUser(): void
    {
        $register = new RegisterUser($this->users, $this->emails, new PhpUuidGenerator());
        $register->execute('Ana Pérez', 'ana@example.com', 'secret123');
    }

    private function app(): WebApp
    {
        return new WebApp(
            new RegisterUser($this->users, $this->emails, new PhpUuidGenerator()),
            new ResendWelcomeEmail($this->users, $this->emails),
            new DeleteUser($this->users),
            new ListUsers($this->users),
            $this->emails,
            new SessionFlash(),
            new View(dirname(__DIR__) . '/public/views'),
            new CsrfToken()
        );
    }
}
