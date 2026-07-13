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
<link rel="stylesheet" href="/cricket/assets/css/style.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="/cricket/index.php">🏏 Cricket Game</a>
    <?php if (is_logged_in()): ?>
    <nav class="site-nav">
        <span class="hello">Hi, <?= htmlspecialchars(current_username()) ?></span>
        <a href="/cricket/auth/logout.php">Logout</a>
    </nav>
    <?php endif; ?>
</header>
<main class="site-main">
