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
$uid = current_user_id();

$match = get_match($pdo, $matchId);
if (!$match || ($match['user1_id'] != $uid && $match['user2_id'] != $uid) || $match['status'] !== 'in_progress' || !is_test_mode($match['mode'])) {
    http_response_code(404);
    echo json_encode(['error' => 'Match not available']);
    exit;
}

$status = test_day_status($match);
if ($status === 'active') {
    http_response_code(409);
    echo json_encode(['error' => "Today's play isn't over yet"]);
    exit;
}
if ($status === 'time_up') {
    finish_test_match($pdo, $matchId);
    echo json_encode(['match_ended' => true]);
    exit;
}

advance_test_day($pdo, $matchId);
echo json_encode(['match_ended' => false]);
