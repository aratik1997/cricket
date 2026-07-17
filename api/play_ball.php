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
$value = (int)($input['stopwatch_value'] ?? -1);
$uid = current_user_id();

if ($value < 0 || $value > 99) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid stopwatch value']);
    exit;
}

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

// Authorization: only the user who owns the batting team may play this ball.
if (!can_play_ball($match, $innings, $uid)) {
    http_response_code(403);
    echo json_encode(['error' => "It's not your turn"]);
    exit;
}

$result = record_ball($pdo, (int)$innings['id'], $value);

echo json_encode([
    'outcome' => $result['outcome'],
    'runs' => $result['runs'],
    'innings_ended' => $result['innings_ended'],
    'match_ended' => $result['match_ended'],
]);
