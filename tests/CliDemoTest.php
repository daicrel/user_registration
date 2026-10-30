<?php

declare(strict_types=1);

namespace Tests;

use App\Infrastructure\PdoUserRepository;
use PHPUnit\Framework\TestCase;

final class CliDemoTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'user_reg_cli_' . bin2hex(random_bytes(8));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCliRegisterPersistsUserInSqliteFile(): void
    {
        $run = $this->runDemo(['Juan Carlos García López', 'juan.garcia@example.com'], "S3gura!#@$\n");

        $this->assertSame(0, $run['code']);
        $this->assertStringContainsString('[OK] Usuario registrado y guardado correctamente en la base de datos.', $run['out']);
        $this->assertStringNotContainsString('S3gura!#@$', $run['out']);
        $this->assertStringNotContainsString('$2y$', $run['out']);
        $this->assertStringNotContainsString('password_hash', $run['out']);

        $reopened = PdoUserRepository::fromProjectSqlite($this->root);
        $found = $reopened->findByEmail('juan.garcia@example.com');
        $this->assertNotNull($found);
        $this->assertSame('Juan Carlos García López', $found->name());
        $this->assertTrue($found->verifyPassword('S3gura!#@$'));
        $this->assertNotSame('S3gura!#@$', $found->passwordHash());
        $this->assertNotFalse(password_get_info($found->passwordHash())['algo']);
        unset($reopened, $found);
        gc_collect_cycles();
    }

    public function testCliRejectsDuplicateEmailWithoutFatalError(): void
    {
        $first = $this->runDemo(['Juan Carlos García López', 'juan.garcia@example.com'], "S3gura!#@$\n");
        $this->assertSame(0, $first['code']);

        $second = $this->runDemo(['María Elena Ruiz Soto', 'JUAN.GARCIA@example.com'], "Cl4ve!#@$\n");

        $this->assertSame(1, $second['code']);
        $this->assertStringContainsString('[ERROR] Este correo electrónico ya está registrado.', $second['out']);
        $this->assertStringNotContainsString('Fatal error', $second['out'] . $second['err']);
        $this->assertStringNotContainsString('S3gura!#@$', $second['out']);
        $this->assertStringNotContainsString('Cl4ve!#@$', $second['out']);
        $this->assertStringNotContainsString('$2y$', $second['out']);

        $users = PdoUserRepository::fromProjectSqlite($this->root);
        $this->assertCount(1, $users->all());
        $this->assertSame('Juan Carlos García López', $users->all()[0]->name());
        unset($users);
        gc_collect_cycles();
    }

    /**
     * @param list<string> $args
     * @return array{code: int, out: string, err: string}
     */
    private function runDemo(array $args, string $stdin): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__) . DIRECTORY_SEPARATOR . 'demo.php'], $args);
        $commandLine = implode(' ', array_map('escapeshellarg', $command));

        $previous = getenv('USER_REGISTRATION_ROOT');
        putenv('USER_REGISTRATION_ROOT=' . $this->root);

        $pipes = [];
        $process = proc_open(
            $commandLine,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        $this->assertIsResource($process);

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($previous === false) {
            putenv('USER_REGISTRATION_ROOT');
        } else {
            putenv('USER_REGISTRATION_ROOT=' . $previous);
        }

        return ['code' => $code, 'out' => $out, 'err' => $err];
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
