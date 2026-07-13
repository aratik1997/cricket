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
    if ($match['mode'] === 'local') {
        $iCall = true; // same device either way
        $callerName = $callerIsTeam1 ? $match['player1_name'] : $match['player2_name'];
    } elseif ($match['mode'] === 'online') {
        $authorizedUser = $callerIsTeam1 ? (int)$match['user1_id'] : (int)$match['user2_id'];
        $iCall = ($authorizedUser === $uid);
        $callerName = current_username();
    } else { // single, worldcup: the human always plays team1
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
    if ($match['mode'] === 'local') {
        $iCallToss = true; // same device either way
        $callerName = $tossWinnerIsTeam1 ? $match['player1_name'] : $match['player2_name'];
    } elseif ($match['mode'] === 'online') {
        $authorizedUser = $tossWinnerIsTeam1 ? (int)$match['user1_id'] : (int)$match['user2_id'];
        $iCallToss = ($authorizedUser === $uid);
        $callerName = current_username();
    } else { // single, worldcup: the human always plays team1
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

// Auto-simulate the computer's innings instantly for single-player / world-cup modes.
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
}

$innings = get_current_innings($pdo, $matchId);
$inningsList = get_innings_list($pdo, $matchId);
$balls = $innings ? get_balls_for_innings($pdo, (int)$innings['id']) : [];

// Work out whose turn it is.
$myTurn = false;
$myName = current_username();
$waitingText = 'Waiting...';

if ($match['mode'] === 'local') {
    $myTurn = true;
    $myName = ((int)$innings['batting_team_id'] === (int)$match['team1_id'])
        ? $match['player1_name'] : $match['player2_name'];
} elseif ($match['mode'] === 'online') {
    $battingIsTeam1 = (int)$innings['batting_team_id'] === (int)$match['team1_id'];
    $myTurn = $battingIsTeam1 ? ((int)$match['user1_id'] === $uid) : ((int)$match['user2_id'] === $uid);
    $waitingText = "Waiting for your opponent's shot...";
} else { // single, worldcup
    $myTurn = (int)$innings['batting_team_id'] === (int)$match['team1_id'];
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
        <div class="innings-summary">
            <?php foreach ($inningsList as $inn): ?>
            <div class="innings-line<?= (!$inn['completed'] && $inn['id'] == $innings['id']) ? ' active' : '' ?>">
                <strong><?= htmlspecialchars($inn['batting_team_code']) ?></strong>:
                <?= (int)$inn['runs'] ?>/<?= (int)$inn['wickets'] ?>
                (<?= floor($inn['balls_bowled'] / 6) ?>.<?= $inn['balls_bowled'] % 6 ?> ov)
                <?php if ($inn['target']): ?><span class="target">target <?= (int)$inn['target'] ?></span><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="match-meta"><?= (int)$match['overs_limit'] ?> overs &middot; <?= (int)$match['wickets_limit'] ?> wickets to lose</p>
        <p class="toss-note"><?= htmlspecialchars(toss_summary_text($match)) ?></p>
        <div class="this-over">
            <span class="label">This over:</span>
            <?php foreach ($currentOverBalls as $b): ?>
                <span class="ball-chip ball-<?= htmlspecialchars($b['outcome']) ?>">
                    <?= $b['outcome'] === 'wicket' ? 'W' : ($b['outcome'] === 'dot' ? '.' : $b['runs_scored']) ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($myTurn): ?>
    <div class="hit-zone">
        <p class="on-strike">Now batting: <strong><?= htmlspecialchars($myName) ?></strong> (<?= htmlspecialchars($innings['batting_team_code']) ?>)</p>
        <div class="stopwatch" id="stopwatch">00</div>
        <button id="hit-btn" class="btn btn-hit">HIT</button>
        <p id="ball-result" class="ball-result"></p>
        <p class="rule-hint">1-6 = runs &middot; 7-9 = dot ball &middot; 0 = WICKET</p>
    </div>
    <?php else: ?>
    <div class="hit-zone spectate">
        <p><?= htmlspecialchars($waitingText) ?></p>
    </div>
    <?php endif; ?>
</div>
<script src="/cricket/assets/js/stopwatch.js"></script>
<script src="/cricket/assets/js/play.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
