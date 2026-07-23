<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$matchId = (int)($_GET['match'] ?? 0);
$match = get_match($pdo, $matchId);
$uid = current_user_id();

if (!$match || ($match['user1_id'] != $uid && $match['user2_id'] != $uid)) {
    http_response_code(404);
    die('Match not found.');
}

if ($match['status'] === 'completed') {
    header('Location: /cricket/scorecard.php?match=' . $matchId);
    exit;
}

if ($match['status'] === 'waiting') {
    $pageTitle = 'Waiting for opponent';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="setup-card" id="waiting-card" data-match="<?= $matchId ?>">
        <h1>Waiting for an opponent&hellip;</h1>
        <p>Share this join code with your opponent:</p>
        <div class="join-code"><?= htmlspecialchars($match['join_code']) ?></div>
        <p class="muted">This page will move on automatically once they join.</p>
    </div>
    <script src="/cricket/assets/js/waiting.js"></script>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($match['status'] === 'toss_call') {
    $callerId = (int)$match['toss_caller_team_id'];
    $callerIsTeam1 = $callerId === (int)$match['team1_id'];

    // Work out whether the *current* logged-in user is the one who must call heads or tails.
    $iCall = false;
    $callerName = '';
    if (in_array($match['mode'], ['local', 'test_local'], true)) {
        $iCall = true; // same device either way
        $callerName = $callerIsTeam1 ? $match['player1_name'] : $match['player2_name'];
    } elseif (in_array($match['mode'], ['online', 'test_online'], true)) {
        $authorizedUser = $callerIsTeam1 ? (int)$match['user1_id'] : (int)$match['user2_id'];
        $iCall = ($authorizedUser === $uid);
        $callerName = current_username();
    } else { // single, worldcup, test: the human always plays team1
        if ($callerIsTeam1) {
            $iCall = true;
            $callerName = current_username();
        } else {
            // Computer calls: decide instantly and move straight on.
            auto_make_toss_call($pdo, $matchId);
            header('Location: /cricket/play.php?match=' . $matchId);
            exit;
        }
    }

    $callerCode = $callerIsTeam1 ? $match['team1_code'] : $match['team2_code'];

    $pageTitle = 'Toss';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="setup-card toss-card" data-match="<?= $matchId ?>" data-status="toss_call">
        <h1>Toss</h1>
        <p class="toss-result"><strong><?= htmlspecialchars($callerCode) ?></strong> will call the coin.</p>
        <?php if ($iCall): ?>
            <p><?= htmlspecialchars($callerName) ?>, call it in the air!</p>
            <div class="toss-choice">
                <button class="btn btn-primary toss-call-btn" data-call="heads">Heads</button>
                <button class="btn btn-primary toss-call-btn" data-call="tails">Tails</button>
            </div>
        <?php else: ?>
            <p class="muted">Waiting for the coin call&hellip;</p>
        <?php endif; ?>
    </div>
    <script src="/cricket/assets/js/toss.js"></script>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

if ($match['status'] === 'toss') {
    $tossWinnerId = (int)$match['toss_winner_team_id'];
    $tossWinnerIsTeam1 = $tossWinnerId === (int)$match['team1_id'];

    // Work out whether the *current* logged-in user is the one who must make the bat/bowl call.
    $iCallToss = false;
    $callerName = '';
    if (in_array($match['mode'], ['local', 'test_local'], true)) {
        $iCallToss = true; // same device either way
        $callerName = $tossWinnerIsTeam1 ? $match['player1_name'] : $match['player2_name'];
    } elseif (in_array($match['mode'], ['online', 'test_online'], true)) {
        $authorizedUser = $tossWinnerIsTeam1 ? (int)$match['user1_id'] : (int)$match['user2_id'];
        $iCallToss = ($authorizedUser === $uid);
        $callerName = current_username();
    } else { // single, worldcup, test: the human always plays team1
        if ($tossWinnerIsTeam1) {
            $iCallToss = true;
            $callerName = current_username();
        } else {
            // Computer won the toss: decide instantly and move straight on.
            auto_decide_toss($pdo, $matchId);
            header('Location: /cricket/play.php?match=' . $matchId);
            exit;
        }
    }

    $tossWinnerCode = $tossWinnerIsTeam1 ? $match['team1_code'] : $match['team2_code'];
    $callResult = ucfirst($match['toss_call']);
    $flipResult = ucfirst($match['toss_flip_result']);

    $pageTitle = 'Toss';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="setup-card toss-card" data-match="<?= $matchId ?>" data-status="toss">
        <h1>Toss</h1>
        <p class="toss-result">Called <strong><?= htmlspecialchars($callResult) ?></strong>, coin landed <strong><?= htmlspecialchars($flipResult) ?></strong>
            &mdash; <strong><?= htmlspecialchars($tossWinnerCode) ?></strong> won the toss!</p>
        <?php if ($iCallToss): ?>
            <p><?= htmlspecialchars($callerName) ?>, what will you do?</p>
            <div class="toss-choice">
                <button class="btn btn-primary toss-btn" data-decision="bat">Bat First</button>
                <button class="btn btn-primary toss-btn" data-decision="bowl">Bowl First</button>
            </div>
        <?php else: ?>
            <p class="muted">Waiting for the toss call&hellip;</p>
        <?php endif; ?>
    </div>
    <script src="/cricket/assets/js/toss.js"></script>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// Test matches (any variant) run on a day clock, checked before anything else on every load.
$testDayStatus = null;
if (is_test_mode($match['mode'])) {
    $testDayStatus = test_day_status($match);
    if ($testDayStatus === 'time_up') {
        finish_test_match($pdo, $matchId);
        header('Location: /cricket/scorecard.php?match=' . $matchId);
        exit;
    }
}

// Auto-simulate the computer's innings instantly for single-player / world-cup / vs-computer test modes.
if (in_array($match['mode'], ['single', 'worldcup'], true)) {
    $innings = get_current_innings($pdo, $matchId);
    if ($innings && !$innings['completed'] && (int)$innings['batting_team_id'] !== (int)$match['team1_id']) {
        simulate_innings($pdo, (int)$innings['id']);
        header('Location: /cricket/play.php?match=' . $matchId);
        exit;
    }
    $match = get_match($pdo, $matchId);
    if ($match['status'] === 'completed') {
        header('Location: /cricket/scorecard.php?match=' . $matchId);
        exit;
    }
} elseif ($match['mode'] === 'test' && $testDayStatus === 'active') {
    $innings = get_current_innings($pdo, $matchId);
    if ($innings && !$innings['completed'] && (int)$innings['batting_team_id'] !== (int)$match['team1_id']) {
        simulate_test_innings($pdo, (int)$innings['id']);
        header('Location: /cricket/play.php?match=' . $matchId);
        exit;
    }
    $match = get_match($pdo, $matchId);
    if ($match['status'] === 'completed') {
        header('Location: /cricket/scorecard.php?match=' . $matchId);
        exit;
    }
}
// 'day_over' (any test variant): fall through — the render below shows the "Stumps" gate instead
// of the HIT screen, regardless of whether the opponent is the computer or another human.

if (is_test_mode($match['mode']) && $testDayStatus === 'day_over') {
    $pageTitle = 'Stumps';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="setup-card day-break-card" data-match="<?= $matchId ?>">
        <h1>Stumps &mdash; Day <?= (int)$match['test_day'] ?> Complete</h1>
        <p class="muted">Day <?= (int)$match['test_day'] ?> of <?= (int)$match['test_days_limit'] ?> has ended.</p>
        <button id="advance-day-btn" type="button" class="btn btn-primary">Start Day <?= (int)$match['test_day'] + 1 ?></button>
    </div>
    <script src="/cricket/assets/js/test_match.js"></script>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle = 'Playing';
require __DIR__ . '/includes/header.php';
?>
<div id="play-root"><?= render_play_screen($pdo, $match, $uid) ?></div>

<script src="/cricket/assets/js/stopwatch.js"></script>
<script src="/cricket/assets/js/stats_modal.js"></script>
<script src="/cricket/assets/js/player_picker.js"></script>
<script src="/cricket/assets/js/test_match.js"></script>
<script src="/cricket/assets/js/play.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
