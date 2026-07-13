<?php
// PDO connection for the Cricket Game.
// LOCAL (XAMPP) defaults are used automatically. On shared hosting, copy
// config.example.php to config.php in this folder and fill in your real
// database credentials — config.php is gitignored so they never get committed.
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    $cfg = require $configFile;
    $DB_HOST = $cfg['db_host'];
    $DB_NAME = $cfg['db_name'];
    $DB_USER = $cfg['db_user'];
    $DB_PASS = $cfg['db_pass'];
} else {
    $DB_HOST = 'localhost';
    $DB_NAME = 'cricket_game';
    $DB_USER = 'root';
    $DB_PASS = '';
}

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    try {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$DB_NAME}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        // Shared hosting DB users often can't CREATE DATABASE — fine as long as it already exists.
    }
    $pdo->exec("USE `{$DB_NAME}`");

    // Bootstrap schema automatically on first run.
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'teams'")->fetchColumn();
    if (!$tableCheck) {
        $sql = file_get_contents(__DIR__ . '/schema.sql');
        $pdo->exec($sql);
    }

    // Lightweight migrations: add columns to databases created before these features existed.
    $hasTossDecision = $pdo->query("SHOW COLUMNS FROM matches LIKE 'toss_decision'")->fetch();
    if (!$hasTossDecision) {
        $pdo->exec("ALTER TABLE matches ADD COLUMN toss_decision ENUM('bat','bowl') NULL AFTER toss_winner_team_id");
        $pdo->exec("ALTER TABLE matches MODIFY status ENUM('waiting','toss','in_progress','completed') NOT NULL DEFAULT 'waiting'");
    }

    $hasTossCall = $pdo->query("SHOW COLUMNS FROM matches LIKE 'toss_call'")->fetch();
    if (!$hasTossCall) {
        $pdo->exec("ALTER TABLE matches ADD COLUMN toss_caller_team_id INT NULL AFTER wickets_limit");
        $pdo->exec("ALTER TABLE matches ADD COLUMN toss_call ENUM('heads','tails') NULL AFTER toss_caller_team_id");
        $pdo->exec("ALTER TABLE matches ADD COLUMN toss_flip_result ENUM('heads','tails') NULL AFTER toss_call");
        $pdo->exec("ALTER TABLE matches MODIFY status ENUM('waiting','toss_call','toss','in_progress','completed') NOT NULL DEFAULT 'waiting'");
    }
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}
