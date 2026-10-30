<?php

declare(strict_types=1);

namespace Tests;

use App\Application\DeleteUser;
use App\Application\ListUsers;
use App\Application\RegisterUser;
use App\Application\ResendWelcomeEmail;
use App\Contracts\UuidGenerator;
use App\Contracts\WelcomeEmailSender;
use App\Domain\EmailAlreadyRegistered;
use App\Domain\InvalidUserData;
use App\Domain\User;
use App\Domain\UserNotFound;
use App\Domain\UserPersistenceFailed;
use App\Domain\WelcomeEmailFailed;
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
        $this->assertNotFalse(password_get_info($user->passwordHash())['algo']);

        $storedHash = $this->pdo->query("SELECT password_hash FROM users WHERE email = 'ana@example.com'")->fetchColumn();
        $this->assertIsString($storedHash);
        $this->assertNotSame('secret123', $storedHash);
        $this->assertTrue(password_verify('secret123', $storedHash));
        $this->assertTrue($this->emails->wasRequestedFor('ana@example.com'));
    }

    public function testRejectsDuplicateEmailAndDoesNotRequestAnotherSend(): void
    {
        $this->register->execute('Ana Pérez', 'ana@example.com', 'secret123');

        $this->expectException(EmailAlreadyRegistered::class);
        $this->expectExceptionMessage('Este correo electrónico ya está registrado.');
        $this->register->execute('Otra Ana', 'ana@example.com', 'secret123');
    }

    public function testRejectsDuplicateEmailRegardlessOfLetterCase(): void
    {
        $this->register->execute('Ana Pérez', 'ana@example.com', 'secret123');

        $this->expectException(EmailAlreadyRegistered::class);
        $this->register->execute('Otra Ana', 'ANA@EXAMPLE.COM', 'otraClave9');
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
            $this->assertSame('No se pudo completar la operación.', $e->getMessage());
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

        $this->assertStringContainsString('EMAIL TEXT NOT NULL COLLATE NOCASE', strtoupper($schemaSql));
        $this->assertStringNotContainsString('COLLATE NOCASE UNIQUE', strtoupper($schemaSql));
        $this->assertStringContainsString('ID CHAR(36)', strtoupper($schemaSql));
        $this->assertTrue($this->sqliteHasUniqueIndexOnEmail($this->pdo));
    }

    public function testSqliteUniqueStopsRawInsertWithoutPhpPrecheck(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO users (id, name, email, password_hash)
             VALUES (:id, :name, :email, :password_hash)'
        );
        $insert->execute([
            ':id' => Uuid::v4(),
            ':name' => 'Uno',
            ':email' => 'unico@example.com',
            ':password_hash' => 'hash-a',
        ]);

        try {
            $insert->execute([
                ':id' => Uuid::v4(),
                ':name' => 'Dos',
                ':email' => 'UNICO@example.com',
                ':password_hash' => 'hash-b',
            ]);
            $this->fail('SQLite UNIQUE debería rechazar el segundo INSERT');
        } catch (\PDOException $e) {
            $this->assertSame('23000', (string) ($e->errorInfo[0] ?? ''));
        }

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM users WHERE email = 'unico@example.com' COLLATE NOCASE"
        )->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testSchemaAddsUniqueIndexOnLegacyTableWithoutColumnConstraint(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec(
            'CREATE TABLE users (
                id CHAR(36) NOT NULL PRIMARY KEY,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                password_hash TEXT NOT NULL
            )'
        );
        $pdo->prepare(
            'INSERT INTO users (id, name, email, password_hash) VALUES (:id, :name, :email, :password_hash)'
        )->execute([
            ':id' => Uuid::v4(),
            ':name' => 'Ana',
            ':email' => 'ana@example.com',
            ':password_hash' => 'hash-a',
        ]);

        PdoUserRepository::createSqliteSchema($pdo);

        $this->assertTrue($this->sqliteHasUniqueIndexOnEmail($pdo));

        $second = $pdo->prepare(
            'INSERT INTO users (id, name, email, password_hash) VALUES (:id, :name, :email, :password_hash)'
        );

        try {
            $second->execute([
                ':id' => Uuid::v4(),
                ':name' => 'Otra',
                ':email' => 'ANA@example.com',
                ':password_hash' => 'hash-b',
            ]);
            $this->fail('El índice UNIQUE debería aplicarse también a tablas antiguas');
        } catch (\PDOException $e) {
            $this->assertSame('23000', (string) ($e->errorInfo[0] ?? ''));
        }
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
            $this->assertSame('Este correo electrónico ya está registrado.', $e->getMessage());
            $this->assertStringNotContainsString('S3gura!#@$', $e->getMessage());
            $this->assertStringNotContainsString('OtraClave!1', $e->getMessage());
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

        $listed = (new ListUsers($this->users))->execute();
        $this->assertSame(['id', 'name', 'email'], array_keys($listed[0]));
        $this->assertSame('Ana Pérez', $listed[0]['name']);
        $this->assertSame('ana@example.com', $listed[0]['email']);

        $usersView = (string) file_get_contents(dirname(__DIR__) . '/public/views/users.php');
        $this->assertStringNotContainsString('password', $usersView);
        $this->assertStringNotContainsString('hash', $usersView);
        $this->assertStringContainsString('name', $usersView);
        $this->assertStringContainsString('email', $usersView);
        $this->assertStringContainsString('id', $usersView);
        $this->assertStringContainsString('_csrf', $usersView);
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

    public function testResendKeepsUserWhenSendFails(): void
    {
        $user = $this->register->execute('Ana Pérez', 'ana@example.com', 'secret123');
        $mailer = new class implements WelcomeEmailSender {
            public function send(User $user): void
            {
                throw new \RuntimeException('SMTP caido');
            }
        };
        $resend = new ResendWelcomeEmail($this->users, $mailer);

        try {
            $resend->execute($user->id());
            $this->fail('Debería informar del fallo del correo');
        } catch (WelcomeEmailFailed $e) {
            $this->assertSame('No se pudo simular el correo de bienvenida.', $e->getMessage());
            $this->assertStringNotContainsString('SMTP', $e->getMessage());
            $this->assertStringNotContainsString('secret123', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            $this->assertNotNull($this->users->findById($user->id()));
            $this->assertSame('ana@example.com', $this->users->findById($user->id())?->email());
        }
    }

    public function testResendRejectsUnknownUserAndDoesNotSend(): void
    {
        $this->register->execute('Ana Pérez', 'ana@example.com', 'secret123');
        $resend = new ResendWelcomeEmail($this->users, $this->emails);

        try {
            $resend->execute(Uuid::v4());
            $this->fail('Debería rechazar un usuario inexistente');
        } catch (UserNotFound) {
            $this->assertCount(1, $this->emails->requested());
        }
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

    public function testFileSqliteKeepsUserAfterNewRepositoryInstance(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'user_reg_pdo_' . bin2hex(random_bytes(8));

        try {
            $first = PdoUserRepository::fromProjectSqlite($root);
            $register = new RegisterUser($first, new FakeWelcomeEmailSender(), new PhpUuidGenerator());
            $register->execute('Ana Pérez', 'persistente@example.com', 'secret123');

            $second = PdoUserRepository::fromProjectSqlite($root);
            $found = $second->findByEmail('persistente@example.com');

            $this->assertNotNull($found);
            $this->assertSame('Ana Pérez', $found->name());
            $this->assertSame('persistente@example.com', $found->email());
            $this->assertTrue($found->verifyPassword('secret123'));
            $this->assertNotSame('secret123', $found->passwordHash());
            $this->assertNotFalse(password_get_info($found->passwordHash())['algo']);
            unset($register, $first, $second, $found);
        } finally {
            gc_collect_cycles();
            $this->removeDirectory($root);
        }
    }

    public function testFileSqliteUniqueIndexRejectsDuplicateEmail(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'user_reg_uniq_' . bin2hex(random_bytes(8));

        try {
            $users = PdoUserRepository::fromProjectSqlite($root);
            $users->save(User::register(Uuid::v4(), 'Uno', 'unico.archivo@example.com', 'secret123'));

            try {
                $users->save(User::register(Uuid::v4(), 'Dos', 'UNICO.ARCHIVO@example.com', 'otraClave9'));
                $this->fail('UNIQUE debería evitar el segundo registro');
            } catch (EmailAlreadyRegistered $e) {
                $this->assertSame('Este correo electrónico ya está registrado.', $e->getMessage());
                $this->assertStringNotContainsString('secret123', $e->getMessage());
                $this->assertCount(1, $users->all());
            }

            unset($users);
            $reopened = PdoUserRepository::fromProjectSqlite($root);
            $this->assertCount(1, $reopened->all());
            $this->assertNotNull($reopened->findByEmail('unico.archivo@example.com'));
            unset($reopened);
        } finally {
            gc_collect_cycles();
            $this->removeDirectory($root);
        }
    }

    public function testDeleteRejectsUnknownUser(): void
    {
        $delete = new DeleteUser($this->users);

        $this->expectException(UserNotFound::class);
        $delete->execute(Uuid::v4());
    }

    private function sqliteHasUniqueIndexOnEmail(PDO $pdo): bool
    {
        $indexes = $pdo->query("PRAGMA index_list('users')")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($indexes as $index) {
            if ((int) $index['unique'] !== 1) {
                continue;
            }

            $columns = $pdo->query('PRAGMA index_info(' . $pdo->quote((string) $index['name']) . ')')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($columns as $column) {
                if (strcasecmp((string) $column['name'], 'email') === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isDir()) {
                @rmdir($path);
                continue;
            }

            for ($intento = 0; $intento < 20; $intento++) {
                if (@unlink($path)) {
                    break;
                }

                gc_collect_cycles();
                usleep(25000);
            }
        }

        @rmdir($directory);
    }
}
