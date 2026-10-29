<aside class="email" id="welcome-email" aria-label="Correo de bienvenida simulado">
    <?php if (is_string($notice)): ?>
        <p class="email-notice"><?= e($notice) ?></p>
    <?php endif; ?>
    <h2>Correo de bienvenida (simulado)</h2>
    <p class="email-meta">
        <span class="email-icon" aria-hidden="true">✉</span>
        Para: <?= e((string) $welcomeEmail['to']) ?>
    </p>
    <p class="email-meta email-subject">Asunto: <?= e((string) $welcomeEmail['subject']) ?></p>
    <div class="email-body">
        <?php foreach (preg_split("/\n\n/", (string) $welcomeEmail['body']) as $paragraph): ?>
            <p><?= e($paragraph) ?></p>
        <?php endforeach; ?>
    </div>
    <p class="email-note"><?= e((string) $welcomeEmail['disclaimer']) ?></p>
</aside>
