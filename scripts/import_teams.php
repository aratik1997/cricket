<?php
/**
 * Imports data/teams.xml into the teams/players tables.
 * Safe to call repeatedly: skips teams that already exist by name.
 */
require_once __DIR__ . '/../config/db.php';

function import_teams(PDO $pdo): void {
    $xmlPath = __DIR__ . '/../data/teams.xml';
    $xml = simplexml_load_file($xmlPath);
    if ($xml === false) {
        throw new RuntimeException('Could not parse teams.xml');
    }

    $insertTeam = $pdo->prepare('INSERT INTO teams (name, short_code, tier) VALUES (?, ?, ?)');
    $insertPlayer = $pdo->prepare('INSERT INTO players (team_id, name, batting_order) VALUES (?, ?, ?)');
    $findTeam = $pdo->prepare('SELECT id FROM teams WHERE name = ?');

    foreach ($xml->team as $team) {
        $name = (string)$team->name;
        $shortCode = (string)$team->short_code;
        $tier = (string)$team['tier'];

        $findTeam->execute([$name]);
        if ($findTeam->fetchColumn()) {
            continue; // already imported
        }

        $insertTeam->execute([$name, $shortCode, $tier]);
        $teamId = (int)$pdo->lastInsertId();

        $order = 1;
        foreach ($team->players->player as $player) {
            $insertPlayer->execute([$teamId, (string)$player, $order]);
            $order++;
        }
    }
}

// Allow running directly from CLI/browser for manual (re)import.
if (php_sapi_name() === 'cli' || basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'import_teams.php') {
    import_teams($pdo);
    echo "Teams imported.\n";
}
