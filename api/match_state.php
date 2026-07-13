<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$matchId = (int)($_GET['match'] ?? 0);
$uid = current_user_id();
$match = get_match($pdo, $matchId);

if (!$match || ($match['user1_id'] != $uid && $match['user2_id'] != $uid)) {
    http_response_code(404);
    echo json_encode(['error' => 'Match not found']);
    exit;
}

$innings = get_current_innings($pdo, $matchId);

echo json_encode([
    'status' => $match['status'],
    'innings_id' => $innings ? (int)$innings['id'] : null,
    'runs' => $innings ? (int)$innings['runs'] : null,
    'wickets' => $innings ? (int)$innings['wickets'] : null,
    'balls_bowled' => $innings ? (int)$innings['balls_bowled'] : null,
]);
