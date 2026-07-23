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
    $countPlayers = $pdo->prepare('SELECT COUNT(*) FROM players WHERE team_id = ?');

    foreach ($xml->team as $team) {
        $name = (string)$team->name;
        $shortCode = (string)$team->short_code;
        $tier = (string)$team['tier'];

        $findTeam->execute([$name]);
        $existingId = $findTeam->fetchColumn();
        if ($existingId) {
            $teamId = (int)$existingId;
        } else {
            $insertTeam->execute([$name, $shortCode, $tier]);
            $teamId = (int)$pdo->lastInsertId();
        }

        // Squads were added after teams were — backfill players for a team that already existed
        // but never got its roster, same as a brand-new team.
        $countPlayers->execute([$teamId]);
        if ((int)$countPlayers->fetchColumn() > 0) {
            continue; // already has its squad
        }

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
