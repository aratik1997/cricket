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
$decision = $input['decision'] ?? '';
$uid = current_user_id();

if (!in_array($decision, ['bat', 'bowl'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid decision']);
    exit;
}

$match = get_match($pdo, $matchId);
if (!$match || ($match['user1_id'] != $uid && $match['user2_id'] != $uid) || $match['status'] !== 'toss') {
    http_response_code(404);
    echo json_encode(['error' => 'Match not available']);
    exit;
}

$tossWinnerIsTeam1 = (int)$match['toss_winner_team_id'] === (int)$match['team1_id'];

$authorized = false;
if ($match['mode'] === 'local') {
    $authorized = ((int)$match['user1_id'] === $uid);
} elseif ($match['mode'] === 'online') {
    $authorizedUser = $tossWinnerIsTeam1 ? (int)$match['user1_id'] : (int)$match['user2_id'];
    $authorized = ($authorizedUser === $uid);
} else { // single, worldcup
    $authorized = $tossWinnerIsTeam1 && ((int)$match['user1_id'] === $uid);
}

if (!$authorized) {
    http_response_code(403);
    echo json_encode(['error' => "It's not your call to make"]);
    exit;
}

apply_toss_decision($pdo, $matchId, $decision);

echo json_encode(['ok' => true]);
