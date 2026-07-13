<?php
if (!isset($pageTitle)) {
    $pageTitle = 'Cricket Game';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> - Cricket Game</title>
<link rel="icon" type="image/svg+xml" href="/cricket/assets/img/favicon.svg?v=2">
<link rel="icon" type="image/png" sizes="32x32" href="/cricket/assets/img/favicon-32.png?v=2">
<link rel="icon" type="image/png" sizes="16x16" href="/cricket/assets/img/favicon-16.png?v=2">
<link rel="apple-touch-icon" href="/cricket/assets/img/apple-touch-icon.png?v=2">
<link rel="manifest" href="/cricket/assets/img/site.webmanifest">
<link rel="shortcut icon" href="/cricket/favicon.ico?v=2">
<link rel="stylesheet" href="/cricket/assets/css/style.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="/cricket/index.php"><img src="/cricket/assets/img/logo.svg" alt="Cricket Game" class="brand-logo"> Cricket Game</a>
    <?php if (is_logged_in()): ?>
    <nav class="site-nav">
        <span class="hello">Hi, <?= htmlspecialchars(current_username()) ?></span>
        <a href="/cricket/auth/logout.php">Logout</a>
    </nav>
    <?php endif; ?>
</header>
<main class="site-main">
