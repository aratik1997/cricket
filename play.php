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

$innings = get_current_innings($pdo, $matchId);
$inningsList = get_innings_list($pdo, $matchId);
$balls = $innings ? get_balls_for_innings($pdo, (int)$innings['id']) : [];

$scorecards = get_match_scorecard($pdo, $matchId);
$currentCard = null;
foreach ($scorecards as $card) {
    if ($card['innings_id'] === (int)$innings['id']) {
        $currentCard = $card;
        break;
    }
}
$strikerName = '';
$bowlerName = '';
if ($currentCard) {
    foreach ($currentCard['batting'] as $b) {
        if ($b['is_striker']) { $strikerName = $b['name']; break; }
    }
    foreach ($currentCard['bowling'] as $bw) {
        if ($bw['is_current']) { $bowlerName = $bw['name']; break; }
    }
}
$isTest = is_test_mode($match['mode']);
$isVsComputer = in_array($match['mode'], ['single', 'worldcup', 'test'], true);
$leadInfo = $isTest ? get_test_lead_info($pdo, $matchId, $innings) : null;

$primedLabels = [];
if ($isTest) {
    if (!empty($innings['pending_four'])) { $primedLabels[] = '4'; }
    if (!empty($innings['pending_five'])) { $primedLabels[] = '5'; }
    if (!empty($innings['pending_six'])) { $primedLabels[] = '6'; }
    if (!empty($innings['pending_wicket'])) { $primedLabels[] = 'Wicket'; }
}

// Work out whose turn it is.
$myTurn = can_play_ball($match, $innings, $uid);
$myName = current_username();
$waitingText = 'Waiting...';

if (in_array($match['mode'], ['local', 'test_local'], true)) {
    $myName = ((int)$innings['batting_team_id'] === (int)$match['team1_id'])
        ? $match['player1_name'] : $match['player2_name'];
} elseif (in_array($match['mode'], ['online', 'test_online'], true)) {
    $waitingText = "Waiting for your opponent's shot...";
}

$currentOverBalls = [];
$curOver = intdiv((int)$innings['balls_bowled'], 6) + 1;
foreach ($balls as $b) {
    if ((int)$b['over_no'] === $curOver) {
        $currentOverBalls[] = $b;
    }
}

$pageTitle = 'Playing';
require __DIR__ . '/includes/header.php';
?>
<div class="play-screen" data-match="<?= $matchId ?>" data-my-turn="<?= $myTurn ? '1' : '0' ?>" data-mode="<?= htmlspecialchars($match['mode']) ?>"
     data-innings-id="<?= (int)$innings['id'] ?>" data-balls-bowled="<?= (int)$innings['balls_bowled'] ?>">
    <div class="scoreboard">
        <h1><?= htmlspecialchars($match['team1_code']) ?> vs <?= htmlspecialchars($match['team2_code']) ?></h1>
        <?php if ($isTest): ?>
        <p class="day-timer-line" id="day-timer" data-seconds="<?= test_day_seconds_left($match) ?>">
            Day <?= (int)$match['test_day'] ?> of <?= (int)$match['test_days_limit'] ?> &middot; <span id="day-timer-clock">--:--</span> left today
        </p>
        <?php endif; ?>
        <div class="innings-summary">
            <?php foreach ($inningsList as $inn): ?>
            <div class="innings-line<?= (!$inn['completed'] && $inn['id'] == $innings['id']) ? ' active' : '' ?>">
                <strong><?= htmlspecialchars($inn['batting_team_code']) ?></strong>:
                <?= (int)$inn['runs'] ?>/<?= (int)$inn['wickets'] ?>
                (<?= floor($inn['balls_bowled'] / 6) ?>.<?= $inn['balls_bowled'] % 6 ?> ov)
                <?php if ($inn['declared']): ?><span class="target">declared</span><?php endif; ?>
                <?php if ($inn['target']): ?><span class="target">target <?= (int)$inn['target'] ?></span><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($leadInfo): ?>
        <p class="lead-line">
            <?php if ($leadInfo['level']): ?>
                Scores level
            <?php else: ?>
                <strong><?= htmlspecialchars($leadInfo['team_code']) ?></strong> lead by <strong><?= (int)$leadInfo['runs'] ?></strong> run<?= (int)$leadInfo['runs'] === 1 ? '' : 's' ?>
            <?php endif; ?>
        </p>
        <?php endif; ?>
        <?php if ($currentCard): ?>
        <p class="run-rate-line">
            CRR: <strong><?= number_format($currentCard['crr'], 2) ?></strong>
            &middot; <strong><?= (int)$currentCard['wickets_left'] ?></strong> wicket<?= (int)$currentCard['wickets_left'] === 1 ? '' : 's' ?> left
            <?php if ($currentCard['required']): ?>
                &middot; Need <strong><?= (int)$currentCard['required']['runs_needed'] ?></strong> run<?= $currentCard['required']['runs_needed'] === 1 ? '' : 's' ?>
                <?php if ($currentCard['required']['balls_left'] !== null): ?>
                    from <strong><?= (int)$currentCard['required']['balls_left'] ?></strong> ball<?= $currentCard['required']['balls_left'] === 1 ? '' : 's' ?> to win
                    &middot; RRR: <strong><?= number_format($currentCard['required']['rrr'], 2) ?></strong>
                <?php else: ?>
                    to win
                <?php endif; ?>
            <?php endif; ?>
        </p>
        <?php endif; ?>
        <?php if ($isTest): ?>
        <p class="match-meta">Test Match &middot; Innings <?= (int)$match['current_innings'] ?> of 4 &middot; <?= (int)$match['wickets_limit'] ?> wickets to lose</p>
        <?php else: ?>
        <p class="match-meta"><?= (int)$match['overs_limit'] ?> overs &middot; <?= (int)$match['wickets_limit'] ?> wickets to lose</p>
        <?php endif; ?>
        <p class="toss-note"><?= htmlspecialchars(toss_summary_text($match)) ?></p>
        <div class="this-over">
            <span class="label">This over:</span>
            <?php foreach ($currentOverBalls as $b): ?>
                <span class="ball-chip ball-<?= htmlspecialchars($b['outcome']) ?>">
                    <?= $b['outcome'] === 'wicket' ? 'W' : ($b['outcome'] === 'dot' ? '.' : $b['runs_scored']) ?>
                </span>
            <?php endforeach; ?>
        </div>
        <?php if ($strikerName || $bowlerName): ?>
        <p class="on-strike-line">
            <?php if ($strikerName): ?>🏏 <strong><?= htmlspecialchars($strikerName) ?></strong> on strike<?php endif; ?>
            <?php if ($strikerName && $bowlerName): ?> &middot; <?php endif; ?>
            <?php if ($bowlerName): ?>🎯 <strong><?= htmlspecialchars($bowlerName) ?></strong> bowling<?php endif; ?>
        </p>
        <?php endif; ?>
        <div class="stats-btn-row">
            <button id="stats-btn" type="button" class="btn btn-small">Player Stats</button>
        </div>
    </div>

    <?php if ($myTurn): ?>
    <div class="hit-zone">
        <p class="on-strike">Now batting: <strong><?= htmlspecialchars($myName) ?></strong> (<?= htmlspecialchars($innings['batting_team_code']) ?>)</p>
        <?php if ($isTest): ?>
        <p id="ball-hint" class="ball-hint"></p>
        <?php if ($primedLabels): ?>
        <p class="primed-line">Primed (one more hit lands it): <strong><?= htmlspecialchars(implode(', ', $primedLabels)) ?></strong></p>
        <?php endif; ?>
        <?php endif; ?>
        <div class="stopwatch" id="stopwatch">00</div>
        <button id="hit-btn" class="btn btn-hit">HIT</button>
        <p id="ball-result" class="ball-result"></p>
        <?php if ($isTest): ?>
        <p class="rule-hint">1-3 = runs &middot; 7-9 = dot ball &middot; 4-6 and 0 (wicket) need the <em>same</em> number twice to land</p>
        <?php else: ?>
        <p class="rule-hint">1-6 = runs &middot; 7-9 = dot ball &middot; 0 = WICKET</p>
        <?php endif; ?>

        <?php if ($isTest): ?>
        <div class="declare-row">
            <button id="declare-btn" type="button" class="btn btn-small btn-declare">Declare Innings</button>
        </div>
        <p id="declare-status" class="muted"></p>
        <?php endif; ?>
        <?php if ($isVsComputer): ?>
        <div class="autoplay-row">
            <span class="label">Autoplay:</span>
            <button type="button" class="btn btn-small autoplay-btn" data-mode="over">1 Over</button>
            <button type="button" class="btn btn-small autoplay-btn" data-mode="half">Half Match</button>
            <button type="button" class="btn btn-small autoplay-btn" data-mode="full">Full Match</button>
            <span class="autoplay-custom">
                <input type="number" id="autoplay-overs" min="1" max="20" value="1" class="autoplay-input" aria-label="Custom overs">
                <button type="button" class="btn btn-small autoplay-btn" data-mode="custom">Custom Overs</button>
            </span>
        </div>
        <p id="autoplay-status" class="muted"></p>
        <?php endif; ?>

        <?php require __DIR__ . '/includes/player_picker.php'; ?>
    </div>
    <?php else: ?>
    <div class="hit-zone spectate">
        <p><?= htmlspecialchars($waitingText) ?></p>
    </div>
    <?php endif; ?>
</div>

<div id="stats-modal" class="modal-overlay" hidden>
    <div class="modal-card">
        <div class="modal-head">
            <h2>Player Stats</h2>
            <button id="stats-close" type="button" class="modal-close" aria-label="Close">&times;</button>
        </div>
        <?php foreach ($scorecards as $card): ?>
            <?= innings_scorecard_html($card) ?>
        <?php endforeach; ?>
    </div>
</div>

<script src="/cricket/assets/js/stopwatch.js"></script>
<script src="/cricket/assets/js/play.js"></script>
<script src="/cricket/assets/js/stats_modal.js"></script>
<script src="/cricket/assets/js/player_picker.js"></script>
<?php if ($isTest): ?>
<script src="/cricket/assets/js/test_match.js"></script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
