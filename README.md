# Registro de usuarios

Aplicación de registro de usuarios en PHP 8.1 o superior. El alta pide nombre completo, email y contraseña. El dominio valida los datos, el email es único sin distinguir mayúsculas de minúsculas, la contraseña se guarda con `password_hash()` y cada usuario obtiene un UUID versión 4.

La persistencia es SQLite mediante PDO. El correo de bienvenida no se envía: `FakeWelcomeEmailSender` simula destinatario, asunto y cuerpo. No hay SMTP ni servicios externos.

El diseño demuestra modularidad, separación de responsabilidades e inversión de dependencias: los casos de uso dependen de puertos (`Contracts`), no de PDO ni del adaptador de correo.

## Características principales

- Registrar usuarios (nombre, email y contraseña).
- Rechazar email inválido, nombre vacío y contraseña de menos de 8 caracteres (`mb_strlen`).
- Impedir emails duplicados, también si solo cambia el uso de mayúsculas.
- Listar usuarios (id, nombre y email; nunca contraseña ni hash).
- Simular el correo de bienvenida tras un alta.
- Volver a solicitar esa simulación para un usuario existente.
- Eliminar usuarios; el email queda libre.
- Coordinar HTTP en `WebApp` (POST `register`, `resend` y `delete`, con redirección PRG).
- Proteger los POST con token CSRF (`CsrfToken`, `hash_equals`).
- Escapar la salida HTML con `htmlspecialchars` (`e()`).

## Tecnologías utilizadas

| Pieza | Uso |
| --- | --- |
| PHP 8.1+ | `composer.json` exige `php: >=8.1`. |
| Composer | Autoload PSR-4: `App\` → `src/`, `Tests\` → `tests/`. |
| PDO SQLite | Extensiones `pdo` y `pdo_sqlite`. |
| `mbstring` | Longitud de contraseña en caracteres. |
| PHPUnit 10.5.62 | Dependencia de desarrollo; `phpunit.xml.dist`. |
| Servidor embebido de PHP | Document root `public/`. |

## Requisitos previos

- PHP 8.1 o superior
- [Composer](https://getcomposer.org/)
- Extensiones `pdo`, `pdo_sqlite` y `mbstring`

```bash
php -v
php -m
```

En `php -m` deben aparecer `pdo` y `pdo_sqlite`.

## Instalación

```bash
composer install
```

Instala dependencias (PHPUnit en desarrollo) y genera `vendor/autoload.php`.

```bash
composer dump-autoload
```

Solo hace falta si `vendor/` ya existe y cambió el PSR-4 o se añadieron clases. No se usa `.env` ni credenciales de correo.

## Ejecución de la aplicación web

```bash
php -S 127.0.0.1:8080 -t public
```

Abre [http://127.0.0.1:8080](http://127.0.0.1:8080).

`-t public` deja `public/` como raíz HTTP. Sin ese flag, el `index.php` de la carpeta del proyecto reenvía a `public/index.php`, pero las URLs `/css/`, `/js/` e `/img/` no coinciden con esos archivos. El modo previsto es `-t public`.

SQLite de la web: `data/users.sqlite`. `WebAppFactory` crea el directorio `data/` si no existe.

| Pieza | Papel |
| --- | --- |
| `public/index.php` | Autoload, `WebAppFactory::create()` y `WebApp::run()`. |
| `WebAppFactory` | PDO, esquema SQLite, adaptadores, casos de uso, CSRF, flash y vistas. |
| `WebApp` | Petición, sesión, CSRF, casos de uso, PRG y datos de plantilla. No crea PDO. |
| `public/views/` | `home.php` incluye `register.php`, `users.php` y `welcome.php`. CSRF en `_csrf.php`. |
| `public/css/` | Layout, formulario, correo simulado y listado. |
| `public/js/register.js` | Scroll al correo simulado, foco en errores y botón «Registrando…» al enviar el alta. |

Tras un POST **correcto** de alta o reenvío, `WebApp` copia `lastEmail()` a la sesión y redirige. La GET siguiente pinta el correo desde ese flash. Si el reenvío lanza `WelcomeEmailFailed`, no hay redirección ni preview: el listado muestra *No se pudo simular el correo de bienvenida.*

## Demo por consola

`demo.php` usa SQLite en memoria (`sqlite::memory:`), no `data/users.sqlite`. Solo ejecuta `RegisterUser` (más `PdoUserRepository`, `FakeWelcomeEmailSender` y `PhpUuidGenerator`).

Interactivo (nombre vacío + Enter para salir). Comprueba email y unicidad antes de pedir la contraseña:

```bash
php demo.php
```

Una sola llamada. Argumentos: nombre, email, contraseña (`$argv[1]`, `$argv[2]`, `$argv[3]`):

```bash
php demo.php "Ana Pérez" "ana@example.com" "secret123"
```

Escribe id, nombre, email y el texto simulado (`lastRequestDescription()`). No imprime contraseña ni hash. Como no hay redirección, el fake sigue siendo la misma instancia.

## Pruebas automatizadas

```bash
php vendor/bin/phpunit
```

`phpunit.xml.dist` arranca `vendor/autoload.php` y ejecuta `tests/`.

Ejecución verificada: PHPUnit 10.5.62, **39 tests, 170 assertions**, código de salida 0.

| Qué | Dónde |
| --- | --- |
| Alta, hash, UUID, email normalizado, correo simulado | `RegisterUserTest` |
| Duplicado (mismo email y distinto casing) | `RegisterUserTest` |
| Índice único SQLite sin pasar por `findByEmail`; `users_email_unique` | `RegisterUserTest` |
| `save` antes de `send`; usuario conservado si `send` lanza; aviso web si no hay preview | `RegisterUserTest`, `WebAppTest` |
| PRG del alta (`testPrgWelcomeEmailComesFromSessionAfterNewAppInstance`) | `WebAppTest` (detalle abajo) |
| Reenvío correcto (datos persistidos, no el POST) | `RegisterUserTest`, `WebAppTest` |
| Reenvío: id inexistente; `send` falla → `WelcomeEmailFailed`, usuario intacto, mensaje fijo | `RegisterUserTest`, `WebAppTest` |
| Borrado y liberación del email | `RegisterUserTest`, `WebAppTest` |
| Listado sin hash | `RegisterUserTest` |
| POST de registro sin CSRF no crea usuario | `WebAppTest` |
| Escape HTML en el listado | `WebAppTest` |
| El caso de uso no nombra PDO ni `FakeWelcomeEmailSender` | `RegisterUserTest` (inspección de fuente) |

`testPrgWelcomeEmailComesFromSessionAfterNewAppInstance` no llama a `WebAppFactory`. Hace un POST de registro con CSRF, comprueba que el usuario quedó guardado y que `$_SESSION['welcome_email']` tiene el destinatario, crea un `FakeWelcomeEmailSender` **nuevo** (sin correos) y un `WebApp` nuevo, simula un GET y comprueba que `welcomeEmail` sale de la sesión (para y nombre correctos) mientras `lastEmail()` del fake nuevo sigue siendo `null`, y que un GET posterior ya no trae `welcomeEmail` (el flash se consumió).

El reenvío y el borrado usan el mismo `CsrfToken::isValid` que el alta; no hay tests HTTP dedicados a POST `resend`/`delete` sin token.

## Estructura del proyecto

```text
user_registration/
├── public/
│   ├── index.php
│   ├── views/
│   │   ├── home.php
│   │   ├── register.php
│   │   ├── users.php
│   │   ├── welcome.php
│   │   └── _csrf.php
│   ├── css/
│   │   ├── home.css
│   │   ├── register.css
│   │   ├── users.css
│   │   └── welcome.css
│   ├── js/
│   │   └── register.js
│   └── img/
│       └── logo.svg
├── src/
│   ├── Application/
│   │   ├── RegisterUser.php
│   │   ├── ListUsers.php
│   │   ├── ResendWelcomeEmail.php
│   │   └── DeleteUser.php
│   ├── Contracts/
│   │   ├── UserRepository.php
│   │   ├── WelcomeEmailSender.php
│   │   ├── WelcomeEmailPreview.php
│   │   └── UuidGenerator.php
│   ├── Domain/
│   │   ├── User.php
│   │   ├── Email.php
│   │   ├── EmailAlreadyRegistered.php
│   │   ├── InvalidUserData.php
│   │   ├── UserNotFound.php
│   │   ├── UserPersistenceFailed.php
│   │   └── WelcomeEmailFailed.php
│   ├── Http/
│   │   ├── WebApp.php
│   │   ├── View.php
│   │   ├── CsrfToken.php
│   │   ├── SessionFlash.php
│   │   └── html.php
│   ├── Infrastructure/
│   │   ├── WebAppFactory.php
│   │   ├── PdoUserRepository.php
│   │   ├── InMemoryUserRepository.php
│   │   ├── FakeWelcomeEmailSender.php
│   │   └── PhpUuidGenerator.php
│   └── Support/
│       └── Uuid.php
├── tests/
│   ├── RegisterUserTest.php
│   └── WebAppTest.php
├── data/
├── vendor/
├── demo.php
├── index.php
├── composer.json
├── composer.lock
├── phpunit.xml.dist
├── .gitignore
└── README.md
```

`/data/*.sqlite` y `vendor/` están en `.gitignore`. `data/` se crea al arrancar la web.

## Arquitectura y responsabilidades

Sin contenedor de inyección ni bus de eventos. Las reglas de negocio no están en las vistas ni en `index.php`.

### Domain

| Tipo | Papel |
| --- | --- |
| `User` | `register()` (valida UUID, nombre, contraseña ≥ 8, hace `password_hash`) y `fromPersistence()`. |
| `Email` | `trim`, minúsculas y `filter_var`. |
| `EmailAlreadyRegistered` | Email ya existente. |
| `InvalidUserData` | Datos de entrada no válidos. |
| `UserNotFound` | Id inexistente en reenvío o borrado. |
| `UserPersistenceFailed` | Mensaje de operación (`guardar` / `eliminar` / genérico), sin SQL. La excepción PDO va en `getPrevious()`. |
| `WelcomeEmailFailed` | Fallo de `send` en el reenvío. Mensaje fijo, sin detalles internos. |

### Application

Método público en todos: `execute(...)`.

| Caso de uso | Qué hace |
| --- | --- |
| `RegisterUser` | `UuidGenerator::v4()`, `User::register`, `findByEmail`, `save`, luego `WelcomeEmailSender::send`. Si `send` lanza, el usuario ya está guardado. La web lo detecta porque `lastEmail()` queda vacío. |
| `ListUsers` | Arrays con `id`, `name` y `email`. |
| `ResendWelcomeEmail` | `findById` y `send`. Si `send` lanza, captura el `Throwable` y lanza `WelcomeEmailFailed` (mensaje fijo; el original va en `getPrevious()`). No borra ni altera al usuario. |
| `DeleteUser` | `findById` y `deleteById`. |

La web usa los cuatro. La demo solo `RegisterUser`.

### HTTP

Coordina transporte. No valida email, no hashea, no habla SQL.

| Tipo | Papel |
| --- | --- |
| `WebApp` | GET/POST, CSRF, casos de uso, flash, redirección o `View`. En reenvío, captura `WelcomeEmailFailed` y muestra el error en el listado. |
| `View` | `extract` + `require` de `public/views/`. |
| `CsrfToken` | Token en `$_SESSION['_csrf_token']`; `hash_equals`. |
| `SessionFlash` | `set` / `pull` (un solo uso). Claves: `success`, `welcome_email`, `notice`. |
| `e()` (`html.php`) | `htmlspecialchars` con `ENT_QUOTES`, `ENT_SUBSTITUTE` y UTF-8. Función global, sin namespace. |

## Puertos e inversión de dependencias

Los casos de uso reciben interfaces por constructor.

| Puerto | Firma | Consumidores |
| --- | --- | --- |
| `UserRepository` | `save`, `findByEmail`, `findById`, `all`, `deleteById` | Los cuatro casos de uso |
| `WelcomeEmailSender` | `send(User $user): void` | `RegisterUser`, `ResendWelcomeEmail` |
| `WelcomeEmailPreview` | `lastEmail(): ?array` | `WebApp` (no los casos de uso) |
| `UuidGenerator` | `v4(): string` | `RegisterUser` |

`send` pide el envío (aquí, simulado y acumulado en memoria). `lastEmail` solo lee el último elemento de esa lista. No son el mismo puerto.

```php
$register = new RegisterUser(
    new PdoUserRepository($pdo),
    new FakeWelcomeEmailSender(),
    new PhpUuidGenerator()
);
```

Otro correo (SMTP u otro) implementaría `WelcomeEmailSender` y se inyectaría en la fábrica. `RegisterUser` no cambia.

## Adaptadores e infraestructura

| Clase | Implementa | Comportamiento |
| --- | --- | --- |
| `PdoUserRepository` | `UserRepository` | Prepares, `ATTR_EMULATE_PREPARES` en false. Tabla `users`: `id CHAR(36)` PK, `email TEXT NOT NULL COLLATE NOCASE`, `password_hash`. Unicidad con el índice `users_email_unique` (`COLLATE NOCASE`), también en bases antiguas. `SQLSTATE 23000` de email → `EmailAlreadyRegistered`. |
| `InMemoryUserRepository` | `UserRepository` | Array cuya clave es el email normalizado. Lo usan los tests de `WebApp`. |
| `FakeWelcomeEmailSender` | `WelcomeEmailSender` y `WelcomeEmailPreview` | Array privado `$requested`. Sin SMTP, sin archivo, sin tabla. |
| `PhpUuidGenerator` | `UuidGenerator` | `App\Support\Uuid::v4()` (`random_bytes`). |
| `WebAppFactory` | — | Cableado de la web. También hay `new PDO` en `demo.php` y en tests. |

### Correo simulado y PRG

En la web, cada petición llama a `WebAppFactory::create()` y obtiene un `FakeWelcomeEmailSender` nuevo. El adaptador **no** guarda correos entre peticiones.

Alta o reenvío **correctos:** POST ejecuta `send()`, `WebApp` copia `lastEmail()` a `$_SESSION['welcome_email']` (más `success` o `notice`) y redirige. La GET pinta `welcome.php` con el flash. `SessionFlash::pull()` lo borra.

Reenvío **fallido:** no se redirige ni se escribe el flash del correo. El usuario permanece. El listado muestra el mensaje de `WelcomeEmailFailed`.

La prueba `testPrgWelcomeEmailComesFromSessionAfterNewAppInstance` cubre solo el PRG del **alta**: POST `register`, sesión con `welcome_email`, `WebApp` nuevo con fake vacío, GET con preview, segundo GET sin preview. No ejercita `WebAppFactory` ni el PRG del reenvío.

En CLI no hay PRG: `demo.php` lee el fake en el mismo proceso.

## Decisiones técnicas

1. **Email único.** Normalización en PHP e índice `users_email_unique` (`COLLATE NOCASE`). El `INSERT` es la garantía si dos peticiones pasan el `findByEmail`.
2. **Contraseñas.** `password_hash(..., PASSWORD_DEFAULT)`. Nunca texto plano.
3. **UUID v4.** `random_bytes(16)` en `Uuid::v4()`, inyectado con `UuidGenerator`.
4. **SQLite.** Web: `data/users.sqlite`. Demo y la mayoría de `RegisterUserTest`: memoria.
5. **Correo.** Fake local. Tras PRG, la UI usa sesión, no el adaptador.
6. **Orden.** `save` y después `send`.
7. **Capas.** Dominio, aplicación, contratos, infraestructura, HTTP.
8. **DIP.** Los `new` concretos están en la fábrica, en `demo.php` o en los tests.

## Seguridad

Mecanismos presentes; no es un endurecimiento de producción (SQLite local, servidor embebido, sesión PHP por defecto).

- CSRF en todos los POST de `WebApp`; token de 32 bytes en hex.
- Escape en vistas con `e()`.
- PDO preparado, sin concatenar SQL.
- Listado y flash de éxito sin hash ni contraseña.
- El cuerpo simulado del correo no incluye la contraseña.
- Errores de persistencia: texto fijo, sin SQL.

## Alcance y limitaciones

Decisiones de esta prueba técnica:

- Sin SMTP y sin envío real.
- SQLite local (archivo o memoria), sin credenciales externas.
- El fake de correo vive solo en la petición actual; la vista de bienvenida en la web depende del flash de sesión. Si `send` falla en el alta, el usuario queda registrado y se avisa sin preview. Si falla en el reenvío, `WelcomeEmailFailed` y el listado muestra el error; el usuario no cambia.
- No hay autenticación de administradores ni HTTPS configurado en el proyecto.
