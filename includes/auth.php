<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../scripts/import_teams.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Bootstrap: import team/player data from XML on first run.
$teamCount = (int)$pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn();
if ($teamCount === 0) {
    import_teams($pdo);
}

function current_user_id(): ?int {
    return $_SESSION['user_id'] ?? null;
}

function current_username(): ?string {
    return $_SESSION['username'] ?? null;
}

function is_logged_in(): bool {
    return current_user_id() !== null;
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: /cricket/auth/login.php');
        exit;
    }
}

function login_user(int $userId, string $username): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $username;
}

function logout_user(): void {
    $_SESSION = [];
    session_destroy();
}
