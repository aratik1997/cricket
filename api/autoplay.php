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
$mode = (string)($input['mode'] ?? '');
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

$ballsIntoOver = (int)$innings['balls_bowled'] % 6;
$oversLimit = (int)$match['overs_limit'];
$allowInningsCross = false;

switch ($mode) {
    case 'over':
        $maxBalls = 6 - $ballsIntoOver;
        break;
    case 'half':
        $maxBalls = max(1, (int)ceil($oversLimit / 2)) * 6;
        break;
    case 'full':
        $maxBalls = 20 * 6 * 2; // generously bounds any legal match length
        $allowInningsCross = true;
        break;
    case 'custom':
        $overs = max(1, min(20, (int)($input['overs'] ?? 1)));
        $maxBalls = $overs * 6;
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid autoplay mode']);
        exit;
}

$result = autoplay_balls($pdo, $matchId, $maxBalls, $uid, $allowInningsCross);

echo json_encode([
    'played' => $result['played'],
    'match_ended' => $result['last']['match_ended'] ?? false,
]);
