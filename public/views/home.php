<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registro de usuarios</title>
    <link rel="stylesheet" href="/css/home.css">
    <link rel="stylesheet" href="/css/register.css">
    <link rel="stylesheet" href="/css/welcome.css">
</head>
<body>
    <div class="layout">
        <?php require __DIR__ . '/register.php'; ?>
        <?php if (is_array($welcomeEmail)): ?>
            <?php require __DIR__ . '/welcome.php'; ?>
        <?php endif; ?>
    </div>
    <script src="/js/register.js" defer></script>
</body>
</html>
