<?php
/**
 * The in-play markup: scoreboard + hit-zone/spectate + stats modal. Rendered via
 * render_play_screen() in functions.php — expects all the variables it sets up to be in scope.
 */
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

        <?php require __DIR__ . '/player_picker.php'; ?>
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
