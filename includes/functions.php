<?php
require_once __DIR__ . '/../config/db.php';

/** Core rule: units digit of the frozen stopwatch value decides the ball outcome. */
function resolve_ball(int $stopwatchValue): array {
    $digit = $stopwatchValue % 10;
    if ($digit === 0) {
        return ['outcome' => 'wicket', 'runs' => 0];
    }
    if ($digit >= 1 && $digit <= 6) {
        return ['outcome' => 'run', 'runs' => $digit];
    }
    return ['outcome' => 'dot', 'runs' => 0];
}

/** Whether $uid is the person allowed to play the next ball of the currently batting team. */
function can_play_ball(array $match, array $innings, int $uid): bool {
    $battingIsTeam1 = (int)$innings['batting_team_id'] === (int)$match['team1_id'];
    if ($match['mode'] === 'local') {
        return true; // same device either way
    }
    if ($match['mode'] === 'online') {
        $authorizedUser = $battingIsTeam1 ? (int)$match['user1_id'] : (int)$match['user2_id'];
        return $authorizedUser === $uid;
    }
    // single, worldcup: the human always plays team1; the computer's innings is auto-simulated.
    return $battingIsTeam1;
}

function get_all_teams(PDO $pdo): array {
    return $pdo->query('SELECT * FROM teams ORDER BY tier = "top10" DESC, name ASC')->fetchAll();
}

function get_top10_teams(PDO $pdo): array {
    $stmt = $pdo->prepare('SELECT * FROM teams WHERE tier = "top10" ORDER BY name ASC');
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_team(PDO $pdo, int $teamId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM teams WHERE id = ?');
    $stmt->execute([$teamId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_team_players(PDO $pdo, int $teamId): array {
    $stmt = $pdo->prepare('SELECT * FROM players WHERE team_id = ? ORDER BY batting_order ASC');
    $stmt->execute([$teamId]);
    return $stmt->fetchAll();
}

/**
 * Inserts a match row. If both team ids are already known, pass 'start_innings' => true
 * (default) to also auto-decide the toss and create the first innings immediately.
 * Online multiplayer creates the row with team2_id = null and starts innings later,
 * once an opponent joins, via begin_innings1().
 */
function create_match_row(PDO $pdo, array $opts): int {
    $stmt = $pdo->prepare('INSERT INTO matches
        (mode, status, team1_id, team2_id, user1_id, user2_id, player1_name, player2_name,
         overs_limit, wickets_limit, join_code, worldcup_fixture_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $opts['mode'],
        $opts['status'] ?? 'waiting',
        $opts['team1_id'],
        $opts['team2_id'] ?? null,
        $opts['user1_id'],
        $opts['user2_id'] ?? null,
        $opts['player1_name'] ?? null,
        $opts['player2_name'] ?? null,
        $opts['overs_limit'],
        $opts['wickets_limit'],
        $opts['join_code'] ?? null,
        $opts['worldcup_fixture_id'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

/** Randomly picks which side calls the coin toss and puts the match into 'toss_call' status. */
function assign_toss_caller(PDO $pdo, int $matchId): void {
    $match = get_match($pdo, $matchId);
    $team1Id = (int)$match['team1_id'];
    $team2Id = (int)$match['team2_id'];
    $caller = (mt_rand(0, 1) === 0) ? $team1Id : $team2Id;

    $pdo->prepare('UPDATE matches SET status = "toss_call", toss_caller_team_id = ? WHERE id = ?')
        ->execute([$caller, $matchId]);
}

/** Flips the coin against the caller's heads/tails call and moves to 'toss' status (awaiting bat/bowl). */
function apply_toss_call(PDO $pdo, int $matchId, string $call): void {
    $match = get_match($pdo, $matchId);
    $callerTeam = (int)$match['toss_caller_team_id'];
    $otherTeam = ($callerTeam === (int)$match['team1_id']) ? (int)$match['team2_id'] : (int)$match['team1_id'];
    $flip = (mt_rand(0, 1) === 0) ? 'heads' : 'tails';
    $winner = ($call === $flip) ? $callerTeam : $otherTeam;

    $pdo->prepare('UPDATE matches SET status = "toss", toss_call = ?, toss_flip_result = ?, toss_winner_team_id = ?
        WHERE id = ?')
        ->execute([$call, $flip, $winner, $matchId]);
}

/** Random heads/tails call for the computer side (single player / world cup AI, and simulated fixtures). */
function auto_make_toss_call(PDO $pdo, int $matchId): void {
    $call = (mt_rand(0, 1) === 0) ? 'heads' : 'tails';
    apply_toss_call($pdo, $matchId, $call);
}

/** Builds a human-readable summary of how the toss was won, for display once it's settled. */
function toss_summary_text(array $match): string {
    if (empty($match['toss_decision'])) {
        return '';
    }
    $callerIsTeam1 = (int)$match['toss_caller_team_id'] === (int)$match['team1_id'];
    $callerCode = $callerIsTeam1 ? $match['team1_code'] : $match['team2_code'];
    $winnerCode = ((int)$match['toss_winner_team_id'] === (int)$match['team1_id']) ? $match['team1_code'] : $match['team2_code'];
    $call = ucfirst($match['toss_call']);
    $flip = ucfirst($match['toss_flip_result']);
    return "{$callerCode} called {$call}, the coin landed {$flip} \xE2\x80\x94 {$winnerCode} won the toss and chose to {$match['toss_decision']}.";
}

/** Opens the batting with the top two in the order and hands the ball to the bowling team's first bowler. */
function init_innings_players(PDO $pdo, int $inningsId, int $battingTeamId, int $bowlingTeamId): void {
    $openers = $pdo->prepare('SELECT id FROM players WHERE team_id = ? ORDER BY batting_order ASC LIMIT 2');
    $openers->execute([$battingTeamId]);
    $openerIds = $openers->fetchAll(PDO::FETCH_COLUMN);

    $bowlerStmt = $pdo->prepare('SELECT id FROM players WHERE team_id = ? ORDER BY batting_order ASC LIMIT 1');
    $bowlerStmt->execute([$bowlingTeamId]);
    $bowlerId = $bowlerStmt->fetchColumn();

    $pdo->prepare('UPDATE innings SET striker_id = ?, non_striker_id = ?, bowler_id = ?, next_bat_order = 3 WHERE id = ?')
        ->execute([$openerIds[0] ?? null, $openerIds[1] ?? null, $bowlerId ?: null, $inningsId]);
}

/** Creates the first innings once the toss call (bat/bowl) is known, and starts the match. */
function start_innings1(PDO $pdo, int $matchId, int $battingTeamId): void {
    $match = get_match($pdo, $matchId);
    $bowlingTeamId = ($battingTeamId === (int)$match['team1_id']) ? (int)$match['team2_id'] : (int)$match['team1_id'];

    $pdo->prepare('UPDATE matches SET status = "in_progress", batting_team_innings1 = ?, current_innings = 1 WHERE id = ?')
        ->execute([$battingTeamId, $matchId]);

    $innStmt = $pdo->prepare('INSERT INTO innings (match_id, innings_no, batting_team_id, bowling_team_id)
        VALUES (?, 1, ?, ?)');
    $innStmt->execute([$matchId, $battingTeamId, $bowlingTeamId]);
    init_innings_players($pdo, (int)$pdo->lastInsertId(), $battingTeamId, $bowlingTeamId);
}

/** Applies the toss winner's bat/bowl call and kicks off the first innings. */
function apply_toss_decision(PDO $pdo, int $matchId, string $decision): void {
    $match = get_match($pdo, $matchId);
    $winner = (int)$match['toss_winner_team_id'];
    $other = ($winner === (int)$match['team1_id']) ? (int)$match['team2_id'] : (int)$match['team1_id'];
    $battingTeam = ($decision === 'bat') ? $winner : $other;

    $pdo->prepare('UPDATE matches SET toss_decision = ? WHERE id = ?')->execute([$decision, $matchId]);
    start_innings1($pdo, $matchId, $battingTeam);
}

/** Random bat/bowl call for the computer side (single player / world cup AI, and simulated fixtures). */
function auto_decide_toss(PDO $pdo, int $matchId): void {
    $decision = (mt_rand(0, 1) === 0) ? 'bat' : 'bowl';
    apply_toss_decision($pdo, $matchId, $decision);
}

/** Convenience for modes where both teams are known up-front (single/local/worldcup): creates the
 * match row and assigns a toss caller, leaving it in 'toss_call' status awaiting a heads/tails call. */
function start_new_match(PDO $pdo, array $opts): int {
    $matchId = create_match_row($pdo, $opts + ['status' => 'waiting']);
    assign_toss_caller($pdo, $matchId);
    return $matchId;
}

function get_match(PDO $pdo, int $matchId): ?array {
    $stmt = $pdo->prepare('SELECT m.*, t1.name AS team1_name, t1.short_code AS team1_code,
            t2.name AS team2_name, t2.short_code AS team2_code,
            tw.name AS winner_name
        FROM matches m
        JOIN teams t1 ON t1.id = m.team1_id
        LEFT JOIN teams t2 ON t2.id = m.team2_id
        LEFT JOIN teams tw ON tw.id = m.winner_team_id
        WHERE m.id = ?');
    $stmt->execute([$matchId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_innings_list(PDO $pdo, int $matchId): array {
    $stmt = $pdo->prepare('SELECT i.*, tb.name AS batting_team_name, tb.short_code AS batting_team_code,
            tw.name AS bowling_team_name
        FROM innings i
        JOIN teams tb ON tb.id = i.batting_team_id
        JOIN teams tw ON tw.id = i.bowling_team_id
        WHERE i.match_id = ? ORDER BY i.innings_no ASC');
    $stmt->execute([$matchId]);
    return $stmt->fetchAll();
}

function get_current_innings(PDO $pdo, int $matchId): ?array {
    $innings = get_innings_list($pdo, $matchId);
    if (!$innings) {
        return null;
    }
    foreach ($innings as $inn) {
        if (!$inn['completed']) {
            return $inn;
        }
    }
    return end($innings) ?: null;
}

function get_balls_for_innings(PDO $pdo, int $inningsId): array {
    $stmt = $pdo->prepare('SELECT * FROM balls WHERE innings_id = ? ORDER BY id ASC');
    $stmt->execute([$inningsId]);
    return $stmt->fetchAll();
}

/**
 * Builds the live batting/bowling scorecard for one innings: the full XI on both sides (including
 * those yet to bat/bowl), their runs/balls/wickets, the current striker/non-striker/bowler, and run rates.
 */
function get_innings_scorecard(PDO $pdo, int $inningsId): ?array {
    $stmt = $pdo->prepare('SELECT i.*, tb.name AS batting_team_name, tb.short_code AS batting_team_code,
            tw.name AS bowling_team_name, tw.short_code AS bowling_team_code
        FROM innings i
        JOIN teams tb ON tb.id = i.batting_team_id
        JOIN teams tw ON tw.id = i.bowling_team_id
        WHERE i.id = ?');
    $stmt->execute([$inningsId]);
    $innings = $stmt->fetch();
    if (!$innings) {
        return null;
    }

    $strikerId = (int)($innings['striker_id'] ?? 0);
    $nonStrikerId = (int)($innings['non_striker_id'] ?? 0);
    $bowlerId = (int)($innings['bowler_id'] ?? 0);

    $battingStmt = $pdo->prepare('SELECT p.id AS player_id, p.name,
            COALESCE(SUM(b.runs_scored), 0) AS runs,
            COUNT(b.id) AS balls,
            MAX(CASE WHEN b.outcome = "wicket" THEN 1 ELSE 0 END) AS is_out
        FROM players p
        LEFT JOIN balls b ON b.batsman_id = p.id AND b.innings_id = ?
        WHERE p.team_id = ?
        GROUP BY p.id, p.name, p.batting_order
        ORDER BY p.batting_order ASC');
    $battingStmt->execute([$inningsId, (int)$innings['batting_team_id']]);
    $batting = [];
    foreach ($battingStmt->fetchAll() as $row) {
        $playerId = (int)$row['player_id'];
        $balls = (int)$row['balls'];
        $batting[] = [
            'player_id' => $playerId,
            'name' => $row['name'],
            'runs' => (int)$row['runs'],
            'balls' => $balls,
            'is_out' => (bool)$row['is_out'],
            'strike_rate' => $balls > 0 ? round($row['runs'] / $balls * 100, 1) : 0.0,
            'is_striker' => $playerId === $strikerId,
            'is_non_striker' => $playerId === $nonStrikerId,
        ];
    }

    $bowlingStmt = $pdo->prepare('SELECT p.id AS player_id, p.name,
            COALESCE(SUM(b.runs_scored), 0) AS runs,
            COUNT(b.id) AS balls,
            COALESCE(SUM(CASE WHEN b.outcome = "wicket" THEN 1 ELSE 0 END), 0) AS wickets
        FROM players p
        LEFT JOIN balls b ON b.bowler_id = p.id AND b.innings_id = ?
        WHERE p.team_id = ?
        GROUP BY p.id, p.name, p.batting_order
        ORDER BY p.batting_order ASC');
    $bowlingStmt->execute([$inningsId, (int)$innings['bowling_team_id']]);
    $bowling = [];
    foreach ($bowlingStmt->fetchAll() as $row) {
        $playerId = (int)$row['player_id'];
        $balls = (int)$row['balls'];
        $bowling[] = [
            'player_id' => $playerId,
            'name' => $row['name'],
            'runs' => (int)$row['runs'],
            'balls' => $balls,
            'wickets' => (int)$row['wickets'],
            'overs_display' => intdiv($balls, 6) . '.' . ($balls % 6),
            'economy' => $balls > 0 ? round($row['runs'] / ($balls / 6), 2) : 0.0,
            'is_current' => $playerId === $bowlerId,
        ];
    }

    $ballsBowled = (int)$innings['balls_bowled'];
    $runs = (int)$innings['runs'];
    $crr = $ballsBowled > 0 ? round($runs / ($ballsBowled / 6), 2) : 0.0;

    $required = null;
    if ((int)$innings['innings_no'] === 2 && $innings['target'] !== null) {
        $matchStmt = $pdo->prepare('SELECT overs_limit FROM matches WHERE id = ?');
        $matchStmt->execute([(int)$innings['match_id']]);
        $maxBalls = (int)$matchStmt->fetchColumn() * 6;
        $runsNeeded = max((int)$innings['target'] - $runs, 0);
        $ballsLeft = max($maxBalls - $ballsBowled, 0);
        $required = [
            'runs_needed' => $runsNeeded,
            'balls_left' => $ballsLeft,
            'rrr' => $ballsLeft > 0 ? round($runsNeeded / ($ballsLeft / 6), 2) : 0.0,
        ];
    }

    return [
        'innings_id' => (int)$innings['id'],
        'innings_no' => (int)$innings['innings_no'],
        'batting_team_name' => $innings['batting_team_name'],
        'batting_team_code' => $innings['batting_team_code'],
        'bowling_team_name' => $innings['bowling_team_name'],
        'bowling_team_code' => $innings['bowling_team_code'],
        'runs' => $runs,
        'wickets' => (int)$innings['wickets'],
        'balls_bowled' => $ballsBowled,
        'target' => $innings['target'] !== null ? (int)$innings['target'] : null,
        'completed' => (bool)$innings['completed'],
        'crr' => $crr,
        'required' => $required,
        'batting' => $batting,
        'bowling' => $bowling,
    ];
}

/** Every innings of a match, as live scorecards — used by the Player Stats panel and the final scorecard. */
function get_match_scorecard(PDO $pdo, int $matchId): array {
    $cards = [];
    foreach (get_innings_list($pdo, $matchId) as $inn) {
        $card = get_innings_scorecard($pdo, (int)$inn['id']);
        if ($card) {
            $cards[] = $card;
        }
    }
    return $cards;
}

/** Renders one innings' batting + bowling scorecard as HTML for the live Player Stats panel / scorecard page. */
function innings_scorecard_html(array $card, bool $showHeader = true): string {
    $html = '<div class="stats-innings">';
    if ($showHeader) {
        $overs = intdiv($card['balls_bowled'], 6) . '.' . ($card['balls_bowled'] % 6);
        $html .= '<h3>' . htmlspecialchars($card['batting_team_code']) . ' ' . (int)$card['runs'] . '/' . (int)$card['wickets']
            . ' <span class="muted">(' . $overs . ' ov &middot; CRR ' . number_format($card['crr'], 2) . ')</span></h3>';
    }

    if ($card['required']) {
        $html .= '<p class="stats-required">Need ' . (int)$card['required']['runs_needed']
            . ' runs off ' . (int)$card['required']['balls_left'] . ' balls'
            . ' &middot; RRR ' . number_format($card['required']['rrr'], 2) . '</p>';
    }

    $html .= '<table class="stats-table"><thead><tr><th>Batter</th><th>R</th><th>B</th><th>SR</th></tr></thead><tbody>';
    foreach ($card['batting'] as $b) {
        $isOnCrease = $b['is_striker'] || $b['is_non_striker'];
        $marker = $b['is_striker'] ? ' *' : '';
        $status = $b['is_out'] ? 'out' : ($isOnCrease ? 'not out' : 'yet to bat');
        $rowClass = $b['is_striker'] ? ' class="stats-active"' : ($status === 'yet to bat' ? ' class="stats-pending"' : '');
        $html .= '<tr' . $rowClass . '><td>' . htmlspecialchars($b['name']) . $marker
            . '<span class="muted"> ' . htmlspecialchars($status) . '</span></td>'
            . '<td>' . (int)$b['runs'] . '</td><td>' . (int)$b['balls'] . '</td>'
            . '<td>' . number_format($b['strike_rate'], 1) . '</td></tr>';
    }
    $html .= '</tbody></table>';

    if ($card['bowling']) {
        $html .= '<table class="stats-table"><thead><tr><th>Bowler</th><th>O</th><th>R</th><th>W</th><th>Econ</th></tr></thead><tbody>';
        foreach ($card['bowling'] as $bw) {
            $hasBowled = $bw['balls'] > 0;
            $rowClass = $bw['is_current'] ? ' class="stats-active"' : (!$hasBowled ? ' class="stats-pending"' : '');
            $marker = $bw['is_current'] ? ' *' : '';
            $econ = $hasBowled ? number_format($bw['economy'], 2) : '&mdash;';
            $html .= '<tr' . $rowClass . '><td>' . htmlspecialchars($bw['name']) . $marker . '</td>'
                . '<td>' . htmlspecialchars($bw['overs_display']) . '</td><td>' . (int)$bw['runs'] . '</td>'
                . '<td>' . (int)$bw['wickets'] . '</td><td>' . $econ . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Records one ball against an innings, advances over/innings/match state as needed.
 * Returns ['outcome'=>..,'runs'=>..,'innings_ended'=>bool,'match_ended'=>bool,'innings'=>row,'match'=>row]
 */
function record_ball(PDO $pdo, int $inningsId, int $stopwatchValue): array {
    $stmt = $pdo->prepare('SELECT * FROM innings WHERE id = ?');
    $stmt->execute([$inningsId]);
    $innings = $stmt->fetch();
    if (!$innings || $innings['completed']) {
        throw new RuntimeException('Innings is not active.');
    }

    $match = get_match($pdo, (int)$innings['match_id']);
    $result = resolve_ball($stopwatchValue);

    $overNo = intdiv((int)$innings['balls_bowled'], 6) + 1;
    $ballNo = ((int)$innings['balls_bowled'] % 6) + 1;
    $strikerId = $innings['striker_id'] ?: null;
    $bowlerId = $innings['bowler_id'] ?: null;

    $insert = $pdo->prepare('INSERT INTO balls (innings_id, over_no, ball_no, stopwatch_value, outcome, runs_scored, batsman_id, bowler_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([$inningsId, $overNo, $ballNo, $stopwatchValue, $result['outcome'], $result['runs'], $strikerId, $bowlerId]);

    $newRuns = (int)$innings['runs'] + $result['runs'];
    $newWickets = (int)$innings['wickets'] + ($result['outcome'] === 'wicket' ? 1 : 0);
    $newBalls = (int)$innings['balls_bowled'] + 1;

    $update = $pdo->prepare('UPDATE innings SET runs = ?, wickets = ?, balls_bowled = ? WHERE id = ?');
    $update->execute([$newRuns, $newWickets, $newBalls, $inningsId]);

    $innings['runs'] = $newRuns;
    $innings['wickets'] = $newWickets;
    $innings['balls_bowled'] = $newBalls;

    $maxBalls = (int)$match['overs_limit'] * 6;
    $inningsEnded = false;
    $reasonTarget = $innings['innings_no'] == 2 && $innings['target'] !== null && $newRuns >= (int)$innings['target'];

    if ($newWickets >= (int)$match['wickets_limit'] || $newBalls >= $maxBalls || $reasonTarget) {
        $inningsEnded = true;
        $pdo->prepare('UPDATE innings SET completed = 1 WHERE id = ?')->execute([$inningsId]);
        $innings['completed'] = 1;
    }

    if (!$inningsEnded) {
        $newStriker = (int)($innings['striker_id'] ?? 0);
        $newNonStriker = (int)($innings['non_striker_id'] ?? 0);
        $newBowler = (int)($innings['bowler_id'] ?? 0);
        $nextBatOrder = (int)($innings['next_bat_order'] ?? 3);

        if ($result['outcome'] === 'wicket') {
            $nextBatsman = $pdo->prepare('SELECT id FROM players WHERE team_id = ? AND batting_order = ?');
            $nextBatsman->execute([(int)$innings['batting_team_id'], $nextBatOrder]);
            $incomingId = $nextBatsman->fetchColumn();
            if ($incomingId) {
                $newStriker = (int)$incomingId;
                $nextBatOrder++;
            }
        } elseif ($result['runs'] % 2 === 1) {
            [$newStriker, $newNonStriker] = [$newNonStriker, $newStriker];
        }

        // Over complete: ends change (strike rotates) and the bowling side hands the ball to the next bowler.
        if ($newBalls % 6 === 0) {
            [$newStriker, $newNonStriker] = [$newNonStriker, $newStriker];
            $bowlingSquad = $pdo->prepare('SELECT id FROM players WHERE team_id = ? ORDER BY batting_order ASC');
            $bowlingSquad->execute([(int)$innings['bowling_team_id']]);
            $squadIds = $bowlingSquad->fetchAll(PDO::FETCH_COLUMN);
            if ($squadIds) {
                $nextOverNo = intdiv($newBalls, 6) + 1;
                $pick = (int)$squadIds[($nextOverNo - 1) % count($squadIds)];
                if ($pick === $newBowler && count($squadIds) > 1) {
                    $pick = (int)$squadIds[$nextOverNo % count($squadIds)];
                }
                $newBowler = $pick;
            }
        }

        $pdo->prepare('UPDATE innings SET striker_id = ?, non_striker_id = ?, bowler_id = ?, next_bat_order = ? WHERE id = ?')
            ->execute([$newStriker ?: null, $newNonStriker ?: null, $newBowler ?: null, $nextBatOrder, $inningsId]);

        $innings['striker_id'] = $newStriker;
        $innings['non_striker_id'] = $newNonStriker;
        $innings['bowler_id'] = $newBowler;
        $innings['next_bat_order'] = $nextBatOrder;
    }

    $matchEnded = false;
    if ($inningsEnded) {
        if ((int)$innings['innings_no'] === 1) {
            $battingTeam2 = (int)$innings['bowling_team_id'];
            $bowlingTeam2 = (int)$innings['batting_team_id'];
            $target = $newRuns + 1;
            $ins2 = $pdo->prepare('INSERT INTO innings (match_id, innings_no, batting_team_id, bowling_team_id, target)
                VALUES (?, 2, ?, ?, ?)');
            $ins2->execute([$match['id'], $battingTeam2, $bowlingTeam2, $target]);
            init_innings_players($pdo, (int)$pdo->lastInsertId(), $battingTeam2, $bowlingTeam2);
            $pdo->prepare('UPDATE matches SET current_innings = 2 WHERE id = ?')->execute([$match['id']]);
        } else {
            finish_match($pdo, (int)$match['id']);
            $matchEnded = true;
        }
    }

    $match = get_match($pdo, (int)$match['id']);

    return [
        'outcome' => $result['outcome'],
        'runs' => $result['runs'],
        'stopwatch_value' => $stopwatchValue,
        'innings_ended' => $inningsEnded,
        'match_ended' => $matchEnded,
        'innings' => $innings,
        'match' => $match,
    ];
}

/**
 * Auto-plays up to $maxBalls random balls on behalf of $uid, for the "Autoplay" controls on the play
 * screen. Stops early if the innings/match ends, or (unless $allowInningsCross) once the current
 * innings finishes — so "1 Over"/"Half Match"/"Custom" never spill into a new innings, while
 * "Full Match" is allowed to keep going through as many innings as $uid still controls.
 */
function autoplay_balls(PDO $pdo, int $matchId, int $maxBalls, int $uid, bool $allowInningsCross = false): array {
    $played = 0;
    $lastResult = null;
    $startInningsId = null;

    for ($i = 0; $i < $maxBalls; $i++) {
        $match = get_match($pdo, $matchId);
        if (!$match || $match['status'] !== 'in_progress') {
            break;
        }
        $innings = get_current_innings($pdo, $matchId);
        if (!$innings || $innings['completed']) {
            break;
        }
        if ($startInningsId === null) {
            $startInningsId = (int)$innings['id'];
        } elseif (!$allowInningsCross && (int)$innings['id'] !== $startInningsId) {
            break;
        }
        if (!can_play_ball($match, $innings, $uid)) {
            break;
        }

        $lastResult = record_ball($pdo, (int)$innings['id'], mt_rand(0, 99));
        $played++;
        if ($lastResult['match_ended']) {
            break;
        }
    }

    return ['played' => $played, 'last' => $lastResult];
}

function finish_match(PDO $pdo, int $matchId): void {
    $innings = get_innings_list($pdo, $matchId);
    $match = get_match($pdo, $matchId);
    if (count($innings) < 2) {
        return;
    }
    $inn1 = $innings[0];
    $inn2 = $innings[1];

    $winnerTeamId = null;
    $resultText = '';

    if ($inn2['runs'] > $inn1['runs']) {
        $winnerTeamId = (int)$inn2['batting_team_id'];
        $wicketsInHand = (int)$match['wickets_limit'] - (int)$inn2['wickets'];
        $resultText = $inn2['batting_team_name'] . " won by " . max($wicketsInHand, 0) . " wicket(s)";
    } elseif ($inn1['runs'] > $inn2['runs']) {
        $winnerTeamId = (int)$inn1['batting_team_id'];
        $margin = (int)$inn1['runs'] - (int)$inn2['runs'];
        $resultText = $inn1['batting_team_name'] . " won by " . $margin . " run(s)";
    } else {
        $resultText = "Match tied";
    }

    $stmt = $pdo->prepare('UPDATE matches SET status = "completed", winner_team_id = ?, result_text = ? WHERE id = ?');
    $stmt->execute([$winnerTeamId, $resultText, $matchId]);
}

/** Instantly simulates every remaining ball of an innings with random stopwatch values (AI turns). */
function simulate_innings(PDO $pdo, int $inningsId): void {
    $stmt = $pdo->prepare('SELECT * FROM innings WHERE id = ?');
    $stmt->execute([$inningsId]);
    $innings = $stmt->fetch();
    if (!$innings || $innings['completed']) {
        return;
    }
    while (true) {
        $value = mt_rand(0, 99);
        $result = record_ball($pdo, $inningsId, $value);
        if ($result['innings_ended']) {
            break;
        }
    }
}

/** Simulates an entire two-innings match instantly (used for World Cup fixtures the user isn't part of). */
function simulate_full_match(PDO $pdo, int $matchId): void {
    $innings = get_current_innings($pdo, $matchId);
    while ($innings) {
        simulate_innings($pdo, (int)$innings['id']);
        $match = get_match($pdo, $matchId);
        if ($match['status'] === 'completed') {
            break;
        }
        $innings = get_current_innings($pdo, $matchId);
    }
}

function generate_join_code(): string {
    return strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

/* =========================================================================
 * World Cup: fixture generation + auto-advance engine
 * ========================================================================= */

function get_worldcup(PDO $pdo, int $worldcupId): ?array {
    $stmt = $pdo->prepare('SELECT w.*, t.short_code AS user_team_code, t.name AS user_team_name
        FROM worldcups w JOIN teams t ON t.id = w.user_team_id WHERE w.id = ?');
    $stmt->execute([$worldcupId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function insert_fixture(PDO $pdo, int $worldcupId, string $round, ?int $t1, ?int $t2, string $status, ?int $winner, int $sortOrder, bool $involvesUser): int {
    $stmt = $pdo->prepare('INSERT INTO worldcup_fixtures
        (worldcup_id, round_name, team1_id, team2_id, winner_team_id, involves_user, status, sort_order)
        VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([$worldcupId, $round, $t1, $t2, $winner, $involvesUser ? 1 : 0, $status, $sortOrder]);
    return (int)$pdo->lastInsertId();
}

function get_round_fixtures(PDO $pdo, int $worldcupId, string $round): array {
    $stmt = $pdo->prepare("SELECT f.*, t1.short_code AS team1_code, t1.name AS team1_name,
            t2.short_code AS team2_code, t2.name AS team2_name
        FROM worldcup_fixtures f
        LEFT JOIN teams t1 ON t1.id = f.team1_id
        LEFT JOIN teams t2 ON t2.id = f.team2_id
        WHERE f.worldcup_id = ? AND f.round_name = ? ORDER BY f.sort_order ASC");
    $stmt->execute([$worldcupId, $round]);
    return $stmt->fetchAll();
}

function has_round(PDO $pdo, int $worldcupId, string $round): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM worldcup_fixtures WHERE worldcup_id = ? AND round_name = ?');
    $stmt->execute([$worldcupId, $round]);
    return (int)$stmt->fetchColumn() > 0;
}

function all_round_resolved(PDO $pdo, int $worldcupId, string $round): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM worldcup_fixtures WHERE worldcup_id = ? AND round_name = ? AND status = 'pending'");
    $stmt->execute([$worldcupId, $round]);
    return (int)$stmt->fetchColumn() === 0;
}

function get_group_numbers(PDO $pdo, int $worldcupId): array {
    $stmt = $pdo->prepare('SELECT DISTINCT group_no FROM worldcup_teams WHERE worldcup_id = ? AND group_no IS NOT NULL ORDER BY group_no ASC');
    $stmt->execute([$worldcupId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function get_group_standings(PDO $pdo, int $worldcupId, int $groupNo): array {
    $stmt = $pdo->prepare('SELECT wt.*, t.name, t.short_code FROM worldcup_teams wt
        JOIN teams t ON t.id = wt.team_id
        WHERE wt.worldcup_id = ? AND wt.group_no = ?
        ORDER BY wt.won DESC, (wt.runs_for - wt.runs_against) DESC');
    $stmt->execute([$worldcupId, $groupNo]);
    return $stmt->fetchAll();
}

function find_blocking_user_fixture(PDO $pdo, int $worldcupId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM worldcup_fixtures
        WHERE worldcup_id = ? AND involves_user = 1 AND status = 'pending'
        ORDER BY sort_order ASC LIMIT 1");
    $stmt->execute([$worldcupId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Classic 8-slot seeding order: index -> seed number (1 = strongest). */
function build_bracket_slots(array $seeds): array {
    $seedOrder = [1, 8, 4, 5, 2, 7, 3, 6];
    $slots = [];
    foreach ($seedOrder as $pos => $seedNum) {
        $slots[$pos] = $seeds[$seedNum - 1] ?? null;
    }
    return $slots;
}

/** Builds the first knockout round from an 8-slot array (may contain nulls = byes). */
function generate_knockout_round(PDO $pdo, int $worldcupId, string $round, array $slots, int $userTeamId, int $sortBase): void {
    $pairs = [[0, 1], [2, 3], [4, 5], [6, 7]];
    $order = $sortBase;
    foreach ($pairs as $pair) {
        $t1 = $slots[$pair[0]] ?? null;
        $t2 = $slots[$pair[1]] ?? null;
        $order++;
        if ($t1 === null && $t2 === null) {
            continue;
        }
        $involvesUser = ($t1 === $userTeamId || $t2 === $userTeamId);
        if ($t1 === null || $t2 === null) {
            $winner = $t1 ?? $t2;
            insert_fixture($pdo, $worldcupId, $round, $t1, $t2, 'bye', $winner, $order, $involvesUser);
        } else {
            insert_fixture($pdo, $worldcupId, $round, $t1, $t2, 'pending', null, $order, $involvesUser);
        }
    }
}

/** Builds the next knockout round by pairing consecutive winners of the previous round. */
function generate_next_knockout_round(PDO $pdo, int $worldcupId, string $fromRound, string $toRound, int $userTeamId, int $sortBase): void {
    $fixtures = get_round_fixtures($pdo, $worldcupId, $fromRound);
    $winners = array_map(fn($f) => (int)$f['winner_team_id'], $fixtures);
    $order = $sortBase;
    for ($i = 0; $i < count($winners); $i += 2) {
        $t1 = $winners[$i];
        $t2 = $winners[$i + 1] ?? null;
        $order++;
        $involvesUser = ($t1 === $userTeamId || $t2 === $userTeamId);
        if ($t2 === null) {
            insert_fixture($pdo, $worldcupId, $toRound, $t1, null, 'bye', $t1, $order, $involvesUser);
        } else {
            insert_fixture($pdo, $worldcupId, $toRound, $t1, $t2, 'pending', null, $order, $involvesUser);
        }
    }
}

function assign_groups_and_fixtures(PDO $pdo, int $worldcupId, array $teamIds, int $userTeamId): void {
    $others = array_values(array_diff($teamIds, [$userTeamId]));
    shuffle($others);
    $ordered = array_merge([$userTeamId], $others);

    $groups = [[], [], [], []];
    foreach ($ordered as $i => $teamId) {
        $groups[$i % 4][] = $teamId;
    }

    foreach ($groups as $gIndex => $teamsInGroup) {
        $groupNo = $gIndex + 1;
        foreach ($teamsInGroup as $teamId) {
            $pdo->prepare('UPDATE worldcup_teams SET group_no = ? WHERE worldcup_id = ? AND team_id = ?')
                ->execute([$groupNo, $worldcupId, $teamId]);
        }
        $order = $groupNo * 100;
        for ($i = 0; $i < count($teamsInGroup); $i++) {
            for ($j = $i + 1; $j < count($teamsInGroup); $j++) {
                $t1 = $teamsInGroup[$i];
                $t2 = $teamsInGroup[$j];
                $order++;
                $involvesUser = ($t1 === $userTeamId || $t2 === $userTeamId);
                insert_fixture($pdo, $worldcupId, 'group', $t1, $t2, 'pending', null, $order, $involvesUser);
            }
        }
    }
}

/** Entry point called once at World Cup creation time. */
function setup_worldcup_fixtures(PDO $pdo, int $worldcupId, array $teamIds, int $userTeamId): void {
    $insertWT = $pdo->prepare('INSERT INTO worldcup_teams (worldcup_id, team_id) VALUES (?, ?)');
    foreach ($teamIds as $tid) {
        $insertWT->execute([$worldcupId, $tid]);
    }

    $n = count($teamIds);
    if ($n <= 8) {
        $others = array_values(array_diff($teamIds, [$userTeamId]));
        shuffle($others);
        $seeds = array_merge([$userTeamId], $others);
        $slots = build_bracket_slots($seeds);
        generate_knockout_round($pdo, $worldcupId, 'QF', $slots, $userTeamId, 1000);
        $pdo->prepare('UPDATE worldcups SET stage = "knockout" WHERE id = ?')->execute([$worldcupId]);
    } else {
        assign_groups_and_fixtures($pdo, $worldcupId, $teamIds, $userTeamId);
        $pdo->prepare('UPDATE worldcups SET stage = "group" WHERE id = ?')->execute([$worldcupId]);
    }
}

function generate_knockout_from_groups(PDO $pdo, int $worldcupId, int $userTeamId): void {
    $groups = get_group_numbers($pdo, $worldcupId);
    $firsts = [];
    $seconds = [];
    foreach ($groups as $g) {
        $standings = get_group_standings($pdo, $worldcupId, $g);
        $firsts[] = (int)$standings[0]['team_id'];
        $seconds[] = (int)$standings[1]['team_id'];
    }
    $seeds = array_merge($firsts, $seconds);
    $slots = build_bracket_slots($seeds);
    generate_knockout_round($pdo, $worldcupId, 'QF', $slots, $userTeamId, 2000);
}

function update_standings_after_fixture(PDO $pdo, int $worldcupId, string $round, int $t1, int $t2, int $s1, int $s2, int $winner): void {
    $loser = ($winner === $t1) ? $t2 : $t1;
    $winnerFor = ($winner === $t1) ? $s1 : $s2;
    $winnerAgainst = ($winner === $t1) ? $s2 : $s1;
    $loserFor = ($loser === $t1) ? $s1 : $s2;
    $loserAgainst = ($loser === $t1) ? $s2 : $s1;

    $pdo->prepare('UPDATE worldcup_teams SET played = played + 1, won = won + 1,
            runs_for = runs_for + ?, runs_against = runs_against + ? WHERE worldcup_id = ? AND team_id = ?')
        ->execute([$winnerFor, $winnerAgainst, $worldcupId, $winner]);

    $eliminate = $round !== 'group' ? ', eliminated = 1' : '';
    $pdo->prepare('UPDATE worldcup_teams SET played = played + 1, lost = lost + 1,
            runs_for = runs_for + ?, runs_against = runs_against + ?' . $eliminate . ' WHERE worldcup_id = ? AND team_id = ?')
        ->execute([$loserFor, $loserAgainst, $worldcupId, $loser]);
}

function complete_fixture_from_match(PDO $pdo, array $fx, array $match): void {
    $innings = get_innings_list($pdo, (int)$match['id']);
    $scoreByTeam = [];
    foreach ($innings as $inn) {
        $scoreByTeam[(int)$inn['batting_team_id']] = (int)$inn['runs'];
    }
    $t1 = (int)$fx['team1_id'];
    $t2 = (int)$fx['team2_id'];
    $s1 = $scoreByTeam[$t1] ?? 0;
    $s2 = $scoreByTeam[$t2] ?? 0;
    $winner = (int)($match['winner_team_id'] ?? 0);
    if ($winner === 0) {
        // Tied match: settle the tournament fixture with a coin flip.
        $winner = (mt_rand(0, 1) === 0) ? $t1 : $t2;
    }

    $pdo->prepare('UPDATE worldcup_fixtures SET status = "completed", team1_score = ?, team2_score = ?,
            winner_team_id = ?, match_id = ? WHERE id = ?')
        ->execute([$s1, $s2, $winner, $match['id'], $fx['id']]);

    update_standings_after_fixture($pdo, (int)$fx['worldcup_id'], $fx['round_name'], $t1, $t2, $s1, $s2, $winner);
}

/** Instantly plays out a fixture that doesn't involve the human player. */
function simulate_fixture(PDO $pdo, array $fx, int $ownerUserId, int $overs, int $wickets): void {
    $t1 = (int)$fx['team1_id'];
    $t2 = (int)$fx['team2_id'];
    $matchId = start_new_match($pdo, [
        'mode' => 'worldcup',
        'team1_id' => $t1,
        'team2_id' => $t2,
        'user1_id' => $ownerUserId,
        'overs_limit' => $overs,
        'wickets_limit' => $wickets,
        'worldcup_fixture_id' => $fx['id'],
    ]);
    auto_make_toss_call($pdo, $matchId);
    auto_decide_toss($pdo, $matchId);
    simulate_full_match($pdo, $matchId);
    $match = get_match($pdo, $matchId);
    complete_fixture_from_match($pdo, $fx, $match);
}

function resolve_pending_fixtures(PDO $pdo, int $worldcupId, int $userTeamId): void {
    $wc = get_worldcup($pdo, $worldcupId);
    $stmt = $pdo->prepare("SELECT * FROM worldcup_fixtures WHERE worldcup_id = ? AND status = 'pending'");
    $stmt->execute([$worldcupId]);
    $fixtures = $stmt->fetchAll();

    foreach ($fixtures as $fx) {
        $involvesUser = ((int)$fx['team1_id'] === $userTeamId || (int)$fx['team2_id'] === $userTeamId);
        if (!$involvesUser) {
            simulate_fixture($pdo, $fx, (int)$wc['user_id'], (int)$wc['overs_limit'], (int)$wc['wickets_limit']);
            continue;
        }
        if ($fx['match_id'] === null) {
            $opponent = ((int)$fx['team1_id'] === $userTeamId) ? (int)$fx['team2_id'] : (int)$fx['team1_id'];
            $matchId = start_new_match($pdo, [
                'mode' => 'worldcup',
                'team1_id' => $userTeamId,
                'team2_id' => $opponent,
                'user1_id' => (int)$wc['user_id'],
                'overs_limit' => (int)$wc['overs_limit'],
                'wickets_limit' => (int)$wc['wickets_limit'],
                'worldcup_fixture_id' => $fx['id'],
            ]);
            $pdo->prepare('UPDATE worldcup_fixtures SET match_id = ? WHERE id = ?')->execute([$matchId, $fx['id']]);
        } else {
            $match = get_match($pdo, (int)$fx['match_id']);
            if ($match && $match['status'] === 'completed') {
                complete_fixture_from_match($pdo, $fx, $match);
            }
            // else: still waiting on the human player to finish their match.
        }
    }
}

/** Advances a World Cup as far as it can go without human input, called on every worldcup.php load. */
function progress_worldcup(PDO $pdo, int $worldcupId): void {
    $wc = get_worldcup($pdo, $worldcupId);
    if (!$wc || $wc['stage'] === 'done') {
        return;
    }
    $userTeamId = (int)$wc['user_team_id'];

    resolve_pending_fixtures($pdo, $worldcupId, $userTeamId);

    if (has_round($pdo, $worldcupId, 'group') && all_round_resolved($pdo, $worldcupId, 'group') && !has_round($pdo, $worldcupId, 'QF')) {
        generate_knockout_from_groups($pdo, $worldcupId, $userTeamId);
        resolve_pending_fixtures($pdo, $worldcupId, $userTeamId);
    }
    if (has_round($pdo, $worldcupId, 'QF') && all_round_resolved($pdo, $worldcupId, 'QF') && !has_round($pdo, $worldcupId, 'SF')) {
        generate_next_knockout_round($pdo, $worldcupId, 'QF', 'SF', $userTeamId, 3000);
        resolve_pending_fixtures($pdo, $worldcupId, $userTeamId);
    }
    if (has_round($pdo, $worldcupId, 'SF') && all_round_resolved($pdo, $worldcupId, 'SF') && !has_round($pdo, $worldcupId, 'F')) {
        generate_next_knockout_round($pdo, $worldcupId, 'SF', 'F', $userTeamId, 4000);
        resolve_pending_fixtures($pdo, $worldcupId, $userTeamId);
    }

    if (has_round($pdo, $worldcupId, 'F') && all_round_resolved($pdo, $worldcupId, 'F')) {
        $final = get_round_fixtures($pdo, $worldcupId, 'F')[0];
        $pdo->prepare('UPDATE worldcups SET stage = "done", champion_team_id = ? WHERE id = ?')
            ->execute([$final['winner_team_id'], $worldcupId]);
    } else {
        $stage = (has_round($pdo, $worldcupId, 'QF')) ? 'knockout' : 'group';
        $pdo->prepare('UPDATE worldcups SET stage = ? WHERE id = ?')->execute([$stage, $worldcupId]);
    }
}

/** Renders <option> tags for a team <select>. */
function team_options_html(array $teams, ?int $selected = null): string {
    $html = '';
    foreach ($teams as $team) {
        $sel = ($selected !== null && (int)$team['id'] === $selected) ? ' selected' : '';
        $html .= '<option value="' . (int)$team['id'] . '"' . $sel . '>'
            . htmlspecialchars($team['name']) . ' (' . htmlspecialchars($team['short_code']) . ')'
            . '</option>';
    }
    return $html;
}
