<?php

declare(strict_types=1);

namespace Tests;

use App\Application\DeleteUser;
use App\Application\RegisterUser;
use App\Application\ResendWelcomeEmail;
use App\Contracts\UuidGenerator;
use App\Contracts\WelcomeEmailSender;
use App\Domain\EmailAlreadyRegistered;
use App\Domain\InvalidUserData;
use App\Domain\User;
use App\Domain\UserPersistenceFailed;
use App\Infrastructure\FakeWelcomeEmailSender;
use App\Infrastructure\PdoUserRepository;
use App\Infrastructure\PhpUuidGenerator;
use App\Support\Uuid;
use PDO;
use PHPUnit\Framework\TestCase;

final class RegisterUserTest extends TestCase
{
    private PDO $pdo;
    private PdoUserRepository $users;
    private FakeWelcomeEmailSender $emails;
    private RegisterUser $register;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        PdoUserRepository::createSqliteSchema($this->pdo);
        $this->users = new PdoUserRepository($this->pdo);
        $this->emails = new FakeWelcomeEmailSender();
        $this->register = new RegisterUser($this->users, $this->emails, new PhpUuidGenerator());
    }

    public function testRegistersUserWithHashedPasswordUuidAndSimulatedWelcomeEmail(): void
    {
        $user = $this->register->execute('Ana Pérez', 'Ana@Example.com', 'secret123');

        $this->assertSame('Ana Pérez', $user->name());
        $this->assertSame('ana@example.com', $user->email());
        $this->assertTrue(Uuid::isValid($user->id()));
        $this->assertNotNull($this->users->findByEmail('ana@example.com'));
        $this->assertNotSame('secret123', $user->passwordHash());
        $this->assertTrue($user->verifyPassword('secret123'));
        $this->assertTrue($this->emails->wasRequestedFor('ana@example.com'));
    }

    public function testRejectsDuplicateEmailAndDoesNotRequestAnotherSend(): void
    {
        $this->register->execute('Ana Pérez', 'ana@example.com', 'secret123');

        $this->expectException(EmailAlreadyRegistered::class);
        $this->register->execute('Otra Ana', 'ana@example.com', 'secret123');
    }

    public function testDuplicateEmailDoesNotRequestWelcomeEmailAgain(): void
    {
        $this->register->execute('Ana Pérez', 'ana@example.com', 'secret123');

        try {
            $this->register->execute('Otra Ana', 'ana@example.com', 'secret123');
            $this->fail('Debería rechazar el email duplicado');
        } catch (EmailAlreadyRegistered) {
            $this->assertCount(1, $this->emails->requested());
        }
    }

    public function testNormalizesEmailWhenSavingAndQuerying(): void
    {
        $user = $this->register->execute('Luis', '  LUIS@Example.com  ', 'secret123');

        $this->assertSame('luis@example.com', $user->email());
        $this->assertNotNull($this->users->findByEmail('Luis@Example.com'));
        $this->assertNotNull($this->users->findByEmail('LUIS@EXAMPLE.COM'));
        $this->assertNotNull($this->users->findByEmail('  luis@example.com  '));
    }

    public function testRejectsInvalidEmailWithoutLeakingPassword(): void
    {
        try {
            $this->register->execute('Luis', 'no-es-un-email', 'secret123');
            $this->fail('Debería rechazar un email inválido');
        } catch (InvalidUserData $e) {
            $this->assertSame('El email no es válido.', $e->getMessage());
            $this->assertStringNotContainsString('secret123', $e->getMessage());
        }
    }

    public function testRejectsShortPasswordWithoutLeakingIt(): void
    {
        try {
            $this->register->execute('Marta', 'marta@example.com', 'corto');
            $this->fail('Debería rechazar una contraseña corta');
        } catch (InvalidUserData $e) {
            $this->assertStringNotContainsString('corto', $e->getMessage());
            $this->assertStringNotContainsString('hash', strtolower($e->getMessage()));
        }
    }

    public function testPersistenceFailureDoesNotLeakSqlOrRequestEmail(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE users (id TEXT)');
        $emails = new FakeWelcomeEmailSender();
        $register = new RegisterUser(new PdoUserRepository($pdo), $emails, new PhpUuidGenerator());

        try {
            $register->execute('Nora', 'nora@example.com', 'secret123');
            $this->fail('Debería fallar al persistir');
        } catch (UserPersistenceFailed $e) {
            $this->assertSame('No se pudo completar el registro.', $e->getMessage());
            $this->assertStringNotContainsString('sql', strtolower($e->getMessage()));
            $this->assertStringNotContainsString('sqlite', strtolower($e->getMessage()));
            $this->assertStringNotContainsString('password_hash', $e->getMessage());
            $this->assertInstanceOf(\PDOException::class, $e->getPrevious());
            $this->assertSame([], $emails->requested());
        }
    }

    public function testDatabaseHasUniqueEmailAndUuidColumn(): void
    {
        $schemaSql = (string) $this->pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'users'")->fetchColumn();

        $this->assertStringContainsString('EMAIL TEXT NOT NULL COLLATE NOCASE UNIQUE', strtoupper($schemaSql));
        $this->assertStringContainsString('ID CHAR(36)', strtoupper($schemaSql));
    }

    public function testUniqueConstraintRejectsSecondInsertWhenPhpCheckIsSkipped(): void
    {
        $first = User::register(Uuid::v4(), 'Duplicado', 'unico@example.com', 'secret123');
        $second = User::register(Uuid::v4(), 'Otro', 'unico@example.com', 'secret123');
        $this->users->save($first);

        $this->expectException(EmailAlreadyRegistered::class);
        $this->users->save($second);
    }

    public function testUuidGeneratorIsRfc4122V4NotUniqid(): void
    {
        $id = Uuid::v4();
        $this->assertTrue(Uuid::isValid($id));
        $this->assertFalse(Uuid::isValid('12345'));
        $this->assertStringNotContainsString('uniqid', (string) file_get_contents(dirname(__DIR__) . '/src/Support/Uuid.php'));
    }

    public function testUserRejectsImprovisedIdentifier(): void
    {
        $this->expectException(InvalidUserData::class);
        $this->expectExceptionMessage('El identificador de usuario no es un UUID válido.');
        User::register('12345', 'Pepe', 'pepe@example.com', 'secret123');
    }

    public function testUserRowIsPersistedWithUuid(): void
    {
        $this->register->execute('Ana Pérez', 'ana@example.com', 'secret123');

        $stored = $this->pdo->prepare('SELECT id, name, email FROM users WHERE email = :email');
        $stored->execute([':email' => 'ana@example.com']);
        $row = $stored->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertSame('Ana Pérez', $row['name']);
        $this->assertSame('ana@example.com', $row['email']);
        $this->assertSame(36, strlen((string) $row['id']));
        $this->assertTrue(Uuid::isValid((string) $row['id']));
    }

    public function testUsesInjectedUuidGenerator(): void
    {
        $uuids = new class implements UuidGenerator {
            public function v4(): string
            {
                return '11111111-1111-4111-8111-111111111111';
            }
        };

        $register = new RegisterUser($this->users, $this->emails, $uuids);
        $user = $register->execute('Ana Pérez', 'ana@example.com', 'secret123');

        $this->assertSame('11111111-1111-4111-8111-111111111111', $user->id());
    }

    public function testUseCaseDependsOnAbstractionsNotAdapters(): void
    {
        $useCaseSource = (string) file_get_contents(dirname(__DIR__) . '/src/Application/RegisterUser.php');

        $this->assertStringNotContainsString('FakeWelcomeEmailSender', $useCaseSource);
        $this->assertStringNotContainsString('PDO', $useCaseSource);
        $this->assertStringNotContainsString('Uuid::', $useCaseSource);
        $this->assertStringNotContainsString('PhpUuidGenerator', $useCaseSource);

        $webSource = (string) file_get_contents(dirname(__DIR__) . '/src/Http/WebApp.php');
        $this->assertStringNotContainsString('PDO', $webSource);
        $this->assertStringNotContainsString('FakeWelcomeEmailSender', $webSource);
        $this->assertStringNotContainsString('PdoUserRepository', $webSource);
        $this->assertStringNotContainsString('sqlite', $webSource);

        $frontSource = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
        $this->assertStringNotContainsString('PDO', $frontSource);
        $this->assertStringNotContainsString('new RegisterUser', $frontSource);
    }

    public function testFakeAdapterDoesNotClaimRealDelivery(): void
    {
        $this->register->execute('Ana Pérez', 'ana@example.com', 'secret123');
        $email = $this->emails->lastEmail();

        $this->assertNotNull($email);
        $this->assertSame('ana@example.com', $email['to']);
        $this->assertSame('¡Bienvenido a nuestra plataforma!', $email['subject']);
        $this->assertStringContainsString('Hola, Ana Pérez:', $email['body']);
        $this->assertStringContainsString('Tu registro se ha completado correctamente.', $email['body']);
        $this->assertStringContainsString('no se ha enviado por correo electrónico', $email['disclaimer']);
    }

    public function testPreservesAccentsAndPasswordsWithSymbols(): void
    {
        $jose = $this->register->execute('José María', 'Jose.Maria@Example.com', 'S3gura!#@$');

        $this->assertSame('José María', $jose->name());
        $this->assertTrue($jose->verifyPassword('S3gura!#@$'));
        $this->assertNotSame('S3gura!#@$', $jose->passwordHash());

        $joseRow = $this->pdo->prepare('SELECT name, email FROM users WHERE email = :email');
        $joseRow->execute([':email' => 'jose.maria@example.com']);
        $joseStored = $joseRow->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($joseStored);
        $this->assertSame('José María', $joseStored['name']);
        $this->assertSame('jose.maria@example.com', $joseStored['email']);
        $this->assertNotNull($this->users->findByEmail('Jose.Maria@Example.com'));
        $this->assertNotNull($this->users->findByEmail('JOSE.MARIA@EXAMPLE.COM'));
        $this->assertNotNull($this->users->findByEmail('  jose.maria@example.com  '));
    }

    public function testErrorsAndFakeAdapterDoNotLeakPasswords(): void
    {
        $this->register->execute('José María', 'jose.maria@example.com', 'S3gura!#@$');

        try {
            $this->register->execute('Otra', 'JOSE.MARIA@EXAMPLE.COM', 'OtraClave!1');
            $this->fail('Debería rechazar el duplicado');
        } catch (EmailAlreadyRegistered $e) {
            $this->assertStringNotContainsString('S3gura!#@$', $e->getMessage());
            $this->assertStringNotContainsString('OtraClave!1', $e->getMessage());
        }

        $secret = 'NoDebeSalir!9';
        try {
            $this->register->execute('Pepe', 'no-es-un-email', $secret);
            $this->fail('Debería rechazar el email inválido');
        } catch (InvalidUserData $e) {
            $this->assertStringNotContainsString($secret, $e->getMessage());
        }

        $this->assertStringNotContainsString('S3gura!#@$', (string) $this->emails->lastRequestDescription());
        $this->assertStringNotContainsString($secret, (string) $this->emails->lastRequestDescription());
    }

    public function testConcurrentInsertsAreStoppedByUniqueConstraint(): void
    {
        $a = User::register(Uuid::v4(), 'Uno', 'carrera@example.com', 'S3gura!#@$');
        $b = User::register(Uuid::v4(), 'Dos', 'carrera@example.com', 'OtraClave!1');

        $this->assertNull($this->users->findByEmail('carrera@example.com'));
        $this->users->save($a);

        try {
            $this->users->save($b);
            $this->fail('UNIQUE debería evitar el segundo registro');
        } catch (EmailAlreadyRegistered $e) {
            $count = (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE email = 'carrera@example.com'")->fetchColumn();
            $this->assertSame(1, $count);
            $this->assertStringNotContainsString('S3gura!#@$', $e->getMessage());
        }
    }

    public function testKeepsUserIfWelcomeEmailFails(): void
    {
        $mailer = new class implements WelcomeEmailSender {
            public function send(User $user): void
            {
                throw new \RuntimeException('SMTP caido');
            }
        };
        $register = new RegisterUser($this->users, $mailer, new PhpUuidGenerator());

        $user = $register->execute('Ana', 'ana@example.com', 'secret123');

        $this->assertSame('ana@example.com', $user->email());
        $this->assertNotNull($this->users->findByEmail('ana@example.com'));
    }

    public function testUniqueConstraintIsCaseInsensitiveEvenIfDomainIsBypassed(): void
    {
        $first = User::fromPersistence(Uuid::v4(), 'Uno', 'Race@Example.com', 'hash-a');
        $second = User::fromPersistence(Uuid::v4(), 'Dos', 'race@example.com', 'hash-b');
        $this->users->save($first);

        $this->expectException(EmailAlreadyRegistered::class);
        $this->users->save($second);
    }

    public function testPasswordLengthUsesCharactersNotBytes(): void
    {
        $this->expectException(InvalidUserData::class);
        User::register(Uuid::v4(), 'Pepe', 'pepe@example.com', 'ááááááá');
    }

    public function testUniqueViolationWithGenericDuplicateMessageMapsToEmailAlreadyRegistered(): void
    {
        $pdo = $this->createMock(PDO::class);
        $statement = $this->createMock(\PDOStatement::class);
        $pdo->method('prepare')->willReturn($statement);
        $statement->method('execute')->willThrowException(new \PDOException(
            'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry',
            23000
        ));

        $repository = new PdoUserRepository($pdo);
        $user = User::register(Uuid::v4(), 'Ana', 'ana@example.com', 'secret123');

        $this->expectException(EmailAlreadyRegistered::class);
        $repository->save($user);
    }

    public function testListsRegisteredUsersWithoutPasswordHashes(): void
    {
        $this->register->execute('Ana Pérez', 'ana@example.com', 'secret123');
        $listed = $this->users->all();

        $this->assertCount(1, $listed);
        $this->assertSame('Ana Pérez', $listed[0]->name());
        $this->assertSame('ana@example.com', $listed[0]->email());
        $this->assertNotSame('', $listed[0]->id());

        $usersView = (string) file_get_contents(dirname(__DIR__) . '/public/views/users.php');
        $this->assertStringNotContainsString('password', $usersView);
        $this->assertStringNotContainsString('hash', $usersView);
        $this->assertStringContainsString('name', $usersView);
        $this->assertStringContainsString('email', $usersView);
        $this->assertStringContainsString('id', $usersView);
    }

    public function testResendWelcomeEmailUsesFakeAdapter(): void
    {
        $user = $this->register->execute('Ana Pérez', 'ana@example.com', 'secret123');
        $resend = new ResendWelcomeEmail($this->users, $this->emails);
        $resend->execute($user->id());

        $this->assertCount(2, $this->emails->requested());
        $last = $this->emails->lastEmail();
        $this->assertNotNull($last);
        $this->assertSame('ana@example.com', $last['to']);
        $this->assertSame('¡Bienvenido a nuestra plataforma!', $last['subject']);
        $this->assertStringContainsString('Hola, Ana Pérez:', $last['body']);
        $this->assertStringNotContainsString('secret123', $last['body']);
    }

    public function testDeletesUserAndFreesTheEmail(): void
    {
        $user = $this->register->execute('Ana Pérez', 'ana@example.com', 'secret123');
        $delete = new DeleteUser($this->users);
        $delete->execute($user->id());

        $this->assertNull($this->users->findById($user->id()));
        $this->assertSame([], $this->users->all());
        $this->register->execute('Otra Ana', 'ana@example.com', 'secret123');
        $this->assertCount(1, $this->users->all());
    }
}
