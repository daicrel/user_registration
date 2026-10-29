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

No hace falta archivo `.env` ni servidor SMTP. La demo usa SQLite en memoria y un adapter de correo falso.

## Configuración

El caso de uso no instancia PDO ni el adapter falso. Recibe las dependencias por constructor:

```php
$register = new RegisterUser(
    new PdoUserRepository($pdo),
    new FakeWelcomeEmailSender()
);
```

Los adapters se sustituyen en el cableado, no en el caso de uso. Estos dos montajes ya existen en el proyecto:

```php
new RegisterUser(new InMemoryUserRepository(), new FakeWelcomeEmailSender());
new RegisterUser(new PdoUserRepository($pdo), new FakeWelcomeEmailSender());
```

Para SMTP, añadirías otra clase que implemente `WelcomeEmailSender` y la pasarías en el constructor. `RegisterUser` no cambia.

## Vista web

En la carpeta del proyecto:

```bash
php -S 127.0.0.1:8080 -t public
```

Abre [http://127.0.0.1:8080](http://127.0.0.1:8080). El `-t public` es obligatorio: si omites eso, PHP busca `index.php` en la raíz del proyecto y puede responder `Not Found`. El formulario está en `public/index.php`. Los usuarios se guardan en `data/users.sqlite`, fuera de `public`.

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
| `Contracts` | `UserRepository` y `WelcomeEmailSender`. |
| `Infrastructure` | Adapters concretos (PDO, memoria, correo falso). |
| `Support` | Generación de UUID v4. |

## Garantías (y lo que no afirma)

1. **Email único de verdad.** Hay un `findByEmail` en PHP para fallar pronto, y además `UNIQUE` en la columna `email`. Si dos peticiones pasan a la vez la comprobación, el `INSERT` choca y se traduce a `EmailAlreadyRegistered`.
2. **UUID v4.** Se genera con `random_bytes`, versión 4 y variante RFC 4122. No se usa `uniqid()`. El id se guarda en `CHAR(36)`.
3. **Guardar y después solicitar el correo.** Si `save` falla, no se llama a `send`. Si `send` falla, el usuario **sigue guardado** y el caso de uso no finge que el registro no existió; el correo se puede reintentar.
4. **El caso de uso depende de interfaces.** `RegisterUser` recibe `UserRepository` y `WelcomeEmailSender` por constructor. No instancia `PdoUserRepository` ni `FakeWelcomeEmailSender`.
5. **El adapter falso no entrega correo.** Solo registra que se *solicitó un envío simulado*. No hay SMTP y no implica que el mensaje haya llegado a una bandeja.
