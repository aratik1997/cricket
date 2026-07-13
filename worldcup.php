<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$worldcupId = (int)($_GET['id'] ?? 0);
$wc = get_worldcup($pdo, $worldcupId);
$uid = current_user_id();

if (!$wc || (int)$wc['user_id'] !== $uid) {
    http_response_code(404);
    die('World Cup not found.');
}

progress_worldcup($pdo, $worldcupId);
$wc = get_worldcup($pdo, $worldcupId);

$blocking = find_blocking_user_fixture($pdo, $worldcupId);
$champion = $wc['champion_team_id'] ? get_team($pdo, (int)$wc['champion_team_id']) : null;

function render_fixture_row(array $f): string {
    $t1 = $f['team1_code'] ?? 'BYE';
    $t2 = $f['team2_code'] ?? 'BYE';
    $line = htmlspecialchars($t1) . ' vs ' . htmlspecialchars($t2);
    if ($f['status'] === 'bye') {
        return '<div class="fixture-row bye">' . $line . ' &mdash; bye</div>';
    }
    if ($f['status'] === 'completed') {
        $winnerCode = ((int)$f['winner_team_id'] === (int)$f['team1_id']) ? $f['team1_code'] : $f['team2_code'];
        return '<div class="fixture-row completed">' . $line . ' &mdash; '
            . (int)$f['team1_score'] . ' / ' . (int)$f['team2_score']
            . ' &mdash; <strong>' . htmlspecialchars($winnerCode) . ' won</strong></div>';
    }
    return '<div class="fixture-row pending">' . $line . ' &mdash; pending</div>';
}

$pageTitle = 'World Cup';
require __DIR__ . '/includes/header.php';
?>
<div class="setup-card">
    <h1>World Cup &mdash; playing as <?= htmlspecialchars($wc['user_team_code']) ?></h1>

    <?php if ($wc['stage'] === 'done'): ?>
        <div class="result-banner champion">🏆 Champion: <?= htmlspecialchars($champion['name']) ?></div>
    <?php elseif ($blocking): ?>
        <div class="alert alert-action">
            Round <?= htmlspecialchars($blocking['round_name']) ?> &mdash; it's your turn to play!
            <a class="btn btn-primary" href="/cricket/play.php?match=<?= (int)$blocking['match_id'] ?>">Play Match</a>
        </div>
    <?php endif; ?>

    <?php if (has_round($pdo, $worldcupId, 'group')): ?>
    <section class="card">
        <h2>Group Stage</h2>
        <?php foreach (get_group_numbers($pdo, $worldcupId) as $g):
            $standings = get_group_standings($pdo, $worldcupId, $g);
            $groupTeamIds = array_map(fn($s) => (int)$s['team_id'], $standings);
        ?>
        <div class="group-block">
            <h3>Group <?= (int)$g ?></h3>
            <table class="standings-table">
                <thead><tr><th>Team</th><th>P</th><th>W</th><th>L</th><th>RF</th><th>RA</th></tr></thead>
                <tbody>
                <?php foreach ($standings as $s): ?>
                    <tr class="<?= (int)$s['team_id'] === (int)$wc['user_team_id'] ? 'me' : '' ?>">
                        <td><?= htmlspecialchars($s['short_code']) ?></td>
                        <td><?= (int)$s['played'] ?></td>
                        <td><?= (int)$s['won'] ?></td>
                        <td><?= (int)$s['lost'] ?></td>
                        <td><?= (int)$s['runs_for'] ?></td>
                        <td><?= (int)$s['runs_against'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="fixture-list">
                <?php foreach (get_round_fixtures($pdo, $worldcupId, 'group') as $f): ?>
                    <?php if (in_array((int)$f['team1_id'], $groupTeamIds, true)): ?>
                        <?= render_fixture_row($f) ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php foreach (['QF' => 'Quarter-finals', 'SF' => 'Semi-finals', 'F' => 'Final'] as $round => $label): ?>
        <?php if (has_round($pdo, $worldcupId, $round)): ?>
        <section class="card">
            <h2><?= $label ?></h2>
            <?php foreach (get_round_fixtures($pdo, $worldcupId, $round) as $f): ?>
                <?= render_fixture_row($f) ?>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>
    <?php endforeach; ?>

    <a class="btn btn-primary" href="/cricket/index.php">Back to Home</a>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
