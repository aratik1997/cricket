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

// The play screen only knows how to render the 'in_progress' state. Any other status (toss,
// waiting, stumps, completed) is a bigger phase change — tell the client to navigate instead.
if ($match['status'] !== 'in_progress' || (is_test_mode($match['mode']) && test_day_status($match) !== 'active')) {
    echo json_encode(['reload' => true]);
    exit;
}

echo json_encode([
    'reload' => false,
    'html' => render_play_screen($pdo, $match, $uid),
]);
