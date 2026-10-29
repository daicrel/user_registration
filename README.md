# Registro de usuarios

Módulo de registro en PHP, organizado para que el dominio no dependa de persistencia ni de correo.

## Requisitos

- PHP 8.1 o superior
- Extensiones `pdo`, `pdo_sqlite` y `mbstring`
- [Composer](https://getcomposer.org/)

Comprueba PHP:

```bash
php -v
php -m
```

En la lista de módulos deben aparecer `pdo` y `pdo_sqlite`.

## Instalación desde cero

En la carpeta del proyecto:

```bash
composer install
```

Si `vendor/` ya existe y solo cambió el autoload:

```bash
composer dump-autoload
```

No hace falta archivo `.env` ni servidor SMTP. **No se envían correos reales**: no hay SMTP, ni credenciales de correo, ni entrega a una bandeja. `FakeWelcomeEmailSender` implementa `WelcomeEmailSender` y solo simula destinatario, asunto y cuerpo (en pantalla o en la demo CLI).

La demo usa SQLite en memoria o, en la vista web, `data/users.sqlite`.

## Configuración

El caso de uso no instancia PDO ni el adapter falso. Recibe las dependencias por constructor:

```php
$register = new RegisterUser(
    new PdoUserRepository($pdo),
    new FakeWelcomeEmailSender(),
    new PhpUuidGenerator()
);
```

Los adapters se sustituyen en el cableado (`WebAppFactory`), no en el caso de uso. Estos dos montajes ya existen en el proyecto:

```php
new RegisterUser(new InMemoryUserRepository(), new FakeWelcomeEmailSender(), new PhpUuidGenerator());
new RegisterUser(new PdoUserRepository($pdo), new FakeWelcomeEmailSender(), new PhpUuidGenerator());
```

Para SMTP, añadirías otra clase que implemente `WelcomeEmailSender` y la pasarías en el constructor. `RegisterUser` no cambia.

## Vista web

En la carpeta del proyecto:

```bash
php -S 127.0.0.1:8080 -t public
```

Abre [http://127.0.0.1:8080](http://127.0.0.1:8080). El `-t public` es obligatorio: si omites eso, PHP busca `index.php` en la raíz del proyecto y puede responder `Not Found`. `public/index.php` solo arranca la aplicación. El formulario está en `public/views/`. Los usuarios se guardan en `data/users.sqlite`, fuera de `public`.

## Cómo ejecutar

Demo interactiva (nombre vacío + Enter para salir):

```bash
php demo.php
```

Registro en una sola línea:

```bash
php demo.php "Ana Pérez" "ana@example.com" "secret123"
```

## Pruebas

```bash
composer install
vendor/bin/phpunit
```

En Windows también vale `php vendor/bin/phpunit`. Debe terminar con código de salida 0.

## Cómo está separado

| Capa | Responsabilidad |
| --- | --- |
| `Domain` | Qué es un usuario válido: nombre, email, contraseña hasheada e identidad. |
| `Application` | Orquesta el caso de uso. Depende de interfaces, no de PDO ni de SMTP. |
| `Contracts` | `UserRepository`, `WelcomeEmailSender`, `WelcomeEmailPreview` y `UuidGenerator`. |
| `Http` | Controlador web: rutas, sesión, CSRF y vistas. No conoce PDO. |
| `Infrastructure` | Adapters concretos (PDO, memoria, correo falso, UUID) y `WebAppFactory`. |
| `Support` | Generación de UUID v4. |

## Garantías (y lo que no afirma)

1. **Email único de verdad.** Hay un `findByEmail` en PHP para fallar pronto (email normalizado a minúsculas), y además `UNIQUE` con `COLLATE NOCASE`. Si dos peticiones pasan a la vez la comprobación, el `INSERT` choca y se traduce a `EmailAlreadyRegistered`. No se puede registrar el mismo correo con distinto uso de mayúsculas.
2. **UUID v4.** Se genera con `random_bytes`, versión 4 y variante RFC 4122. No se usa `uniqid()`. El id se guarda en `CHAR(36)`.
3. **Guardar y después solicitar el correo.** Si `save` falla, no se llama a `send`. Si `send` falla, el usuario **sigue guardado** y el caso de uso no finge que el registro no existió; el correo se puede reintentar.
4. **El caso de uso depende de interfaces.** `RegisterUser` recibe `UserRepository`, `WelcomeEmailSender` y `UuidGenerator` por constructor. No instancia `PdoUserRepository`, `FakeWelcomeEmailSender` ni genera UUID por su cuenta. La web (`Http\WebApp`) no conoce PDO: el cableado está en `WebAppFactory`.
5. **El adapter falso no entrega correo.** `WelcomeEmailSender` es el puerto; `FakeWelcomeEmailSender` muestra para, asunto y cuerpo. No hay SMTP. El registro del usuario y la simulación del correo están separados: primero se persiste, después se pide el envío simulado.
6. **Formularios POST con CSRF.** Cada POST lleva un token de sesión; si falta o no coincide, no se registra, reenvía ni borra. Las vistas escapan con `htmlspecialchars` (`e()`). El listado solo muestra id, nombre y email: nunca la contraseña ni el hash.
