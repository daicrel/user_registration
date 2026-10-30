<section class="card users">
    <header class="brand">
        <h1>Usuarios registrados</h1>
    </header>

    <?php if (is_string($error) && $page === 'users'): ?>
        <div class="msg err" role="alert" tabindex="-1"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($listedUsers === []): ?>
        <p class="empty">Aún no hay usuarios registrados.</p>
    <?php else: ?>
        <ul class="user-list">
            <?php foreach ($listedUsers as $listedUser): ?>
                <li class="user-item">
                    <p class="user-name"><?= e((string) $listedUser['name']) ?></p>
                    <p class="user-email"><?= e((string) $listedUser['email']) ?></p>
                    <p class="user-id">Id: <code><?= e((string) $listedUser['id']) ?></code></p>
                    <div class="user-actions">
                        <form method="post" action="/?page=users">
                            <?php require __DIR__ . '/_csrf.php'; ?>
                            <input type="hidden" name="action" value="resend">
                            <input type="hidden" name="user_id" value="<?= e((string) $listedUser['id']) ?>">
                            <button class="btn btn-secondary" type="submit">Reenvío de correo</button>
                        </form>
                        <form
                            class="js-delete-user"
                            method="post"
                            action="/?page=users"
                            data-name="<?= e((string) $listedUser['name']) ?>"
                            data-email="<?= e((string) $listedUser['email']) ?>"
                        >
                            <?php require __DIR__ . '/_csrf.php'; ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="user_id" value="<?= e((string) $listedUser['id']) ?>">
                            <button class="btn btn-danger" type="submit">Borrar usuario</button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p class="nav-link"><a href="/">Registrar otro usuario</a></p>
</section>

<dialog id="confirm-delete" class="confirm-dialog" aria-labelledby="confirm-delete-title" aria-describedby="confirm-delete-text">
    <h2 id="confirm-delete-title">Eliminar usuario</h2>
    <p id="confirm-delete-text"></p>
    <div class="confirm-actions">
        <button type="button" class="btn btn-secondary" id="confirm-delete-cancel">Cancelar</button>
        <button type="button" class="btn btn-danger" id="confirm-delete-ok">Eliminar</button>
    </div>
</dialog>
