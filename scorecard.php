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

$inningsList = get_innings_list($pdo, $matchId);
$scorecards = get_match_scorecard($pdo, $matchId);
$scorecardByInningsId = [];
foreach ($scorecards as $card) {
    $scorecardByInningsId[$card['innings_id']] = $card;
}

$pageTitle = 'Scorecard';
require __DIR__ . '/includes/header.php';
?>
<div class="setup-card">
    <h1><?= htmlspecialchars($match['team1_code']) ?> vs <?= htmlspecialchars($match['team2_code']) ?></h1>
    <?php if ($match['toss_decision']): ?>
        <p class="toss-note"><?= htmlspecialchars(toss_summary_text($match)) ?></p>
    <?php endif; ?>

    <?php if ($match['status'] === 'completed'): ?>
        <p class="result-banner"><?= htmlspecialchars($match['result_text']) ?></p>
    <?php else: ?>
        <p class="muted">Match still in progress.</p>
        <a class="btn btn-primary" href="/cricket/play.php?match=<?= $matchId ?>">Continue Playing</a>
    <?php endif; ?>

    <?php foreach ($inningsList as $inn): ?>
    <div class="innings-card">
        <h2><?= htmlspecialchars($inn['batting_team_name']) ?><?php if (is_test_mode($match['mode'])): ?> <span class="muted">&mdash; innings <?= (int)$inn['innings_no'] ?></span><?php endif; ?></h2>
        <p class="score-big"><?= (int)$inn['runs'] ?>/<?= (int)$inn['wickets'] ?>
            <span class="overs">(<?= floor($inn['balls_bowled'] / 6) ?>.<?= $inn['balls_bowled'] % 6 ?> overs)</span>
            <?php if ($inn['declared']): ?><span class="target">declared</span><?php endif; ?>
        </p>
        <?php if ($inn['target']): ?><p class="muted">Target: <?= (int)$inn['target'] ?></p><?php endif; ?>
        <?php $balls = get_balls_for_innings($pdo, (int)$inn['id']);
        $overs = [];
        foreach ($balls as $b) { $overs[(int)$b['over_no']][] = $b; } ?>
        <div class="over-list">
            <?php foreach ($overs as $overNo => $overBalls): ?>
            <div class="over-row">
                <span class="over-label">Ov <?= $overNo ?>:</span>
                <?php foreach ($overBalls as $b): ?>
                    <span class="ball-chip ball-<?= htmlspecialchars($b['outcome']) ?>">
                        <?= $b['outcome'] === 'wicket' ? 'W' : ($b['outcome'] === 'dot' ? '.' : $b['runs_scored']) ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (isset($scorecardByInningsId[(int)$inn['id']])): ?>
            <?= innings_scorecard_html($scorecardByInningsId[(int)$inn['id']], false) ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if ($match['mode'] === 'worldcup' && $match['worldcup_fixture_id']): ?>
        <?php
        $wcStmt = $pdo->prepare('SELECT worldcup_id FROM worldcup_fixtures WHERE id = ?');
        $wcStmt->execute([$match['worldcup_fixture_id']]);
        $wcId = $wcStmt->fetchColumn();
        ?>
        <a class="btn btn-primary" href="/cricket/worldcup.php?id=<?= (int)$wcId ?>">Back to World Cup</a>
    <?php else: ?>
        <a class="btn btn-primary" href="/cricket/index.php">Back to Home</a>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
