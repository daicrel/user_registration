<main class="card">
    <header class="brand">
        <img class="logo" src="/img/logo.svg" alt="Registro de usuarios" width="72" height="72">
        <h1>Registro de usuarios</h1>
    </header>

    <?php if (is_array($success)): ?>
        <div class="msg ok" role="status">
            <p><strong>Usuario registrado</strong></p>
            <p><?= e((string) $success['name']) ?> · <?= e((string) $success['email']) ?></p>
        </div>
        <?php if (is_string($notice)): ?>
            <div class="msg warn" role="status"><?= e($notice) ?></div>
        <?php endif; ?>
        <div class="actions">
            <a class="btn" href="/">Registrar otro usuario</a>
            <a class="btn btn-secondary" href="/?page=users">Ver usuarios</a>
        </div>
    <?php endif; ?>

    <?php if (is_string($error)): ?>
        <div class="msg err" role="alert" tabindex="-1"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!is_array($success)): ?>
        <form id="register-form" method="post" action="" autocomplete="on">
            <?php require __DIR__ . '/_csrf.php'; ?>
            <input type="hidden" name="action" value="register">

            <label for="name">Nombre completo</label>
            <input id="name" name="name" type="text" required value="<?= e($name) ?>" placeholder="Juan Carlos García López">

            <label for="email">Email</label>
            <input id="email" name="email" type="email" required value="<?= e($email) ?>" placeholder="juan.garcia@example.com" aria-describedby="email-status" autocomplete="email">
            <p id="email-status" class="email-status" role="status" aria-live="polite"></p>

            <label for="password">Contraseña</label>
            <input id="password" name="password" type="password" required minlength="8" placeholder="Juan2026#01">

            <button type="submit">Registrarme</button>
        </form>
        <p class="nav-link"><a href="/?page=users">Ver usuarios</a></p>
    <?php endif; ?>
</main>
