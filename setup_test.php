<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$teams = get_top10_teams($pdo);
$error = '';
$joinError = '';

$type = $_GET['type'] ?? 'computer';
if (!in_array($type, ['computer', 'local', 'online'], true)) {
    $type = 'computer';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $variant = $_POST['variant'] ?? '';
    $wickets = max(1, min(10, (int)($_POST['wickets'] ?? 10)));

    if ($variant === 'computer') {
        $type = 'computer';
        $teamId = (int)($_POST['team_id'] ?? 0);
        $oppId = (int)($_POST['opponent_id'] ?? 0);

        if ($teamId === $oppId) {
            $error = 'Pick two different teams.';
        } elseif (!get_team($pdo, $teamId) || !get_team($pdo, $oppId)) {
            $error = 'Invalid team selection.';
        } else {
            $matchId = start_new_match($pdo, [
                'mode' => 'test',
                'team1_id' => $teamId,
                'team2_id' => $oppId,
                'user1_id' => current_user_id(),
                'overs_limit' => 90,
                'wickets_limit' => $wickets,
            ]);
            header('Location: /cricket/play.php?match=' . $matchId);
            exit;
        }
    } elseif ($variant === 'local') {
        $type = 'local';
        $p1 = trim($_POST['player1_name'] ?? 'Player 1');
        $p2 = trim($_POST['player2_name'] ?? 'Player 2');
        $teamId = (int)($_POST['team_id'] ?? 0);
        $oppId = (int)($_POST['opponent_id'] ?? 0);

        if ($teamId === $oppId) {
            $error = 'Pick two different teams.';
        } elseif (!get_team($pdo, $teamId) || !get_team($pdo, $oppId)) {
            $error = 'Invalid team selection.';
        } else {
            $matchId = start_new_match($pdo, [
                'mode' => 'test_local',
                'team1_id' => $teamId,
                'team2_id' => $oppId,
                'user1_id' => current_user_id(),
                'player1_name' => $p1 !== '' ? $p1 : 'Player 1',
                'player2_name' => $p2 !== '' ? $p2 : 'Player 2',
                'overs_limit' => 90,
                'wickets_limit' => $wickets,
            ]);
            header('Location: /cricket/play.php?match=' . $matchId);
            exit;
        }
    } elseif ($variant === 'online_create') {
        $type = 'online';
        $teamId = (int)($_POST['team_id'] ?? 0);

        if (!get_team($pdo, $teamId)) {
            $error = 'Pick a team.';
        } else {
            $code = generate_join_code();
            $matchId = create_match_row($pdo, [
                'mode' => 'test_online',
                'status' => 'waiting',
                'team1_id' => $teamId,
                'user1_id' => current_user_id(),
                'overs_limit' => 90,
                'wickets_limit' => $wickets,
                'join_code' => $code,
            ]);
            header('Location: /cricket/play.php?match=' . $matchId);
            exit;
        }
    } elseif ($variant === 'online_join') {
        $type = 'online';
        $code = strtoupper(trim($_POST['join_code'] ?? ''));
        $teamId = (int)($_POST['opponent_team_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM matches WHERE join_code = ? AND status = 'waiting' AND mode = 'test_online'");
        $stmt->execute([$code]);
        $match = $stmt->fetch();

        if (!$match) {
            $joinError = 'No waiting test match found with that code.';
        } elseif ((int)$match['user1_id'] === current_user_id()) {
            $joinError = 'You cannot join your own match.';
        } elseif (!get_team($pdo, $teamId)) {
            $joinError = 'Pick a team.';
        } elseif ($teamId === (int)$match['team1_id']) {
            $joinError = 'Choose a different team than your opponent.';
        } else {
            $pdo->prepare('UPDATE matches SET team2_id = ?, user2_id = ? WHERE id = ?')
                ->execute([$teamId, current_user_id(), $match['id']]);
            assign_toss_caller($pdo, (int)$match['id']);
            header('Location: /cricket/play.php?match=' . $match['id']);
            exit;
        }
    }
}

$pageTitle = 'Test Match Setup';
require __DIR__ . '/includes/header.php';
?>
<div class="setup-card">
    <h1>Test Match</h1>
    <p class="muted">A 5-day game. Each day is compressed into 20 real minutes, shown on a live clock.
        Four innings are played (bat, bowl, bat, bowl) &mdash; each side bats until all out or they
        declare. Runs and wickets come slower than the quick formats, matching Test cricket's pace.
        If day 5 runs out before the match is settled, it's a draw.</p>

    <div class="mode-tab-row">
        <a class="mode-tab<?= $type === 'computer' ? ' active' : '' ?>" href="?type=computer">
            <span class="mode-tab-icon">🤖</span> Vs Computer
        </a>
        <a class="mode-tab<?= $type === 'local' ? ' active' : '' ?>" href="?type=local">
            <span class="mode-tab-icon">📱</span> Same Screen
        </a>
        <a class="mode-tab<?= $type === 'online' ? ' active' : '' ?>" href="?type=online">
            <span class="mode-tab-icon">🌐</span> Online
        </a>
    </div>

    <?php if ($type === 'computer'): ?>
        <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" class="stack-form">
            <input type="hidden" name="variant" value="computer">
            <label>Your Team
                <select name="team_id" required>
                    <option value="">-- choose --</option>
                    <?= team_options_html($teams) ?>
                </select>
            </label>
            <label>Opponent (Computer)
                <select name="opponent_id" required>
                    <option value="">-- choose --</option>
                    <?= team_options_html($teams) ?>
                </select>
            </label>
            <label>Wickets to lose (per innings)
                <input type="number" name="wickets" value="10" min="1" max="10" required>
            </label>
            <button type="submit" class="btn btn-primary">Start Test Match</button>
        </form>
    <?php elseif ($type === 'local'): ?>
        <p class="muted">Both players share this device, passing it back and forth on each innings.</p>
        <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" class="stack-form">
            <input type="hidden" name="variant" value="local">
            <label>Player 1 Name
                <input type="text" name="player1_name" maxlength="40" value="Player 1">
            </label>
            <label>Player 1's Team
                <select name="team_id" required>
                    <option value="">-- choose --</option>
                    <?= team_options_html($teams) ?>
                </select>
            </label>
            <label>Player 2 Name
                <input type="text" name="player2_name" maxlength="40" value="Player 2">
            </label>
            <label>Player 2's Team
                <select name="opponent_id" required>
                    <option value="">-- choose --</option>
                    <?= team_options_html($teams) ?>
                </select>
            </label>
            <label>Wickets to lose (per innings)
                <input type="number" name="wickets" value="10" min="1" max="10" required>
            </label>
            <button type="submit" class="btn btn-primary">Start Test Match</button>
        </form>
    <?php else: ?>
        <h2>Create a Match</h2>
        <?php if ($error): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" class="stack-form">
            <input type="hidden" name="variant" value="online_create">
            <label>Your Team
                <select name="team_id" required>
                    <option value="">-- choose --</option>
                    <?= team_options_html($teams) ?>
                </select>
            </label>
            <label>Wickets to lose (per innings)
                <input type="number" name="wickets" value="10" min="1" max="10" required>
            </label>
            <button type="submit" class="btn btn-primary">Create Match &amp; Get Code</button>
        </form>

        <h2>Join a Match</h2>
        <?php if ($joinError): ?><div class="alert"><?= htmlspecialchars($joinError) ?></div><?php endif; ?>
        <form method="post" class="stack-form">
            <input type="hidden" name="variant" value="online_join">
            <label>Join Code
                <input type="text" name="join_code" maxlength="8" required style="text-transform:uppercase">
            </label>
            <label>Your Team
                <select name="opponent_team_id" required>
                    <option value="">-- choose --</option>
                    <?= team_options_html($teams) ?>
                </select>
            </label>
            <button type="submit" class="btn btn-primary">Join Match</button>
        </form>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
