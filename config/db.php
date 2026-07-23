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

    // Accounts no longer use passwords — just a chosen name.
    $passwordCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'password_hash'")->fetch();
    if ($passwordCol && strtoupper($passwordCol['Null']) === 'NO') {
        $pdo->exec("ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL");
    }

    // Per-player batting/bowling tracking (striker, non-striker, current bowler + who faced/bowled each ball).
    $hasStriker = $pdo->query("SHOW COLUMNS FROM innings LIKE 'striker_id'")->fetch();
    if (!$hasStriker) {
        $pdo->exec("ALTER TABLE innings ADD COLUMN striker_id INT NULL");
        $pdo->exec("ALTER TABLE innings ADD COLUMN non_striker_id INT NULL");
        $pdo->exec("ALTER TABLE innings ADD COLUMN bowler_id INT NULL");
        $pdo->exec("ALTER TABLE innings ADD COLUMN next_bat_order TINYINT NOT NULL DEFAULT 3");
    }
    $hasBallBatsman = $pdo->query("SHOW COLUMNS FROM balls LIKE 'batsman_id'")->fetch();
    if (!$hasBallBatsman) {
        $pdo->exec("ALTER TABLE balls ADD COLUMN batsman_id INT NULL");
        $pdo->exec("ALTER TABLE balls ADD COLUMN bowler_id INT NULL");
    }

    // Test match mode: 5-day/4-innings games with a compressed day timer and manual player picks.
    $hasTestDay = $pdo->query("SHOW COLUMNS FROM matches LIKE 'test_day'")->fetch();
    if (!$hasTestDay) {
        $pdo->exec("ALTER TABLE matches MODIFY mode ENUM('single','online','local','worldcup','test') NOT NULL");
        $pdo->exec("ALTER TABLE matches MODIFY status ENUM('waiting','toss_call','toss','in_progress','day_break','completed') NOT NULL DEFAULT 'waiting'");
        $pdo->exec("ALTER TABLE matches ADD COLUMN test_day TINYINT NULL");
        $pdo->exec("ALTER TABLE matches ADD COLUMN test_days_limit TINYINT NOT NULL DEFAULT 5");
        $pdo->exec("ALTER TABLE matches ADD COLUMN test_day_minutes SMALLINT NOT NULL DEFAULT 20");
        $pdo->exec("ALTER TABLE matches ADD COLUMN test_day_started_at DATETIME NULL");
    }
    $hasDeclared = $pdo->query("SHOW COLUMNS FROM innings LIKE 'declared'")->fetch();
    if (!$hasDeclared) {
        $pdo->exec("ALTER TABLE innings ADD COLUMN declared TINYINT(1) NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE innings ADD COLUMN manual_next_batsman_id INT NULL");
        $pdo->exec("ALTER TABLE innings ADD COLUMN manual_next_bowler_id INT NULL");
    }

    // Test matches can also be played same-screen or online, not just against the computer.
    $modeCol = $pdo->query("SHOW COLUMNS FROM matches LIKE 'mode'")->fetch();
    if ($modeCol && strpos($modeCol['Type'], 'test_local') === false) {
        $pdo->exec("ALTER TABLE matches MODIFY mode ENUM('single','online','local','worldcup','test','test_local','test_online') NOT NULL");
    }

    // Test-match "hit it twice" rule: a 4, 5, 6 or wicket only actually lands the second time that
    // same digit comes up (any ball later, not necessarily the next one).
    $hasPendingFour = $pdo->query("SHOW COLUMNS FROM innings LIKE 'pending_four'")->fetch();
    if (!$hasPendingFour) {
        $pdo->exec("ALTER TABLE innings ADD COLUMN pending_four TINYINT(1) NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE innings ADD COLUMN pending_five TINYINT(1) NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE innings ADD COLUMN pending_six TINYINT(1) NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE innings ADD COLUMN pending_wicket TINYINT(1) NOT NULL DEFAULT 0");
    }

    // The players table (squads, used for stats + next-batsman/bowler picking) was added after the
    // initial schema. Databases whose 'teams' table already existed by then never got it, since the
    // schema-bootstrap block above only runs when 'teams' is missing.
    $hasPlayers = $pdo->query("SHOW TABLES LIKE 'players'")->fetchColumn();
    if (!$hasPlayers) {
        $pdo->exec("CREATE TABLE players (
            id INT AUTO_INCREMENT PRIMARY KEY,
            team_id INT NOT NULL,
            name VARCHAR(60) NOT NULL,
            batting_order TINYINT NOT NULL,
            FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
    }
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}
