<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$matchId = (int)($input['match_id'] ?? 0);
$role = (string)($input['role'] ?? '');
$playerId = (int)($input['player_id'] ?? 0);
$uid = current_user_id();

$match = get_match($pdo, $matchId);
if (!$match || ($match['user1_id'] != $uid && $match['user2_id'] != $uid) || $match['status'] !== 'in_progress') {
    http_response_code(404);
    echo json_encode(['error' => 'Match not available']);
    exit;
}

$innings = get_current_innings($pdo, $matchId);
if (!$innings || $innings['completed']) {
    http_response_code(409);
    echo json_encode(['error' => 'No active innings']);
    exit;
}

if (!can_play_ball($match, $innings, $uid)) {
    http_response_code(403);
    echo json_encode(['error' => "It's not your turn"]);
    exit;
}

if ($role !== 'batsman' && $role !== 'bowler') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid role']);
    exit;
}

// player_id = 0 clears the override, going back to automatic order/rotation.
if ($playerId !== 0) {
    $teamId = $role === 'bowler' ? (int)$innings['bowling_team_id'] : (int)$innings['batting_team_id'];
    $playerStmt = $pdo->prepare('SELECT id FROM players WHERE id = ? AND team_id = ?');
    $playerStmt->execute([$playerId, $teamId]);
    if (!$playerStmt->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid player']);
        exit;
    }
}

if ($role === 'bowler') {
    set_next_bowler($pdo, (int)$innings['id'], $playerId);
} else {
    set_next_batsman($pdo, (int)$innings['id'], $playerId);
}

echo json_encode(['ok' => true]);
