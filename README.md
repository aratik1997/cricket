# 🏏 Reflex Cricket

A browser-based cricket game built with **PHP, MySQL, vanilla JavaScript, and XML**. Instead of simulating shot selection, batting is a **reflex timing game**: a millisecond stopwatch spins from `00` to `99`, and the instant you hit the button decides whether you scored runs, played a dot ball, or lost your wicket.

Play solo against the computer, pass-and-play with a friend on one device, challenge someone online, or run an entire single-player World Cup tournament with real fixtures, groups, and a knockout bracket.

## How it plays

A stopwatch cycles through milliseconds (`00`–`99`) continuously. Press **HIT** to freeze it — the **units digit** of the frozen value decides the ball:

| Units digit | Outcome |
|---|---|
| `1`–`6` | That many runs |
| `7`–`9` | Dot ball (0 runs) |
| `0` | Wicket |

Every match opens with a **coin toss**: one side calls Heads or Tails in the air, the coin is flipped, and whoever wins picks Bat First or Bowl First. Innings are limited by a configurable number of overs and wickets; the second innings chases a target set by the first, and the match ends the moment the target is reached, the overs run out, or wickets are exhausted.

## Game modes

- **Single Player** — bat and bowl against the computer. The computer's turns resolve instantly so you're never waiting.
- **Same Screen Multiplayer** — two players share one device/browser, handing it back and forth between innings.
- **Multiplayer (Online)** — create a match to get a join code, or enter a friend's code to join them from another device. Turns sync automatically (AJAX polling), and only the batting side's browser can play a ball.
- **World Cup (Single Player)** — pick your team and a tournament size from 6–16 teams; the rest of the field is filled in automatically.
  - 6–8 teams → straight knockout (top seeds get a bye into the semis).
  - 9–16 teams → four groups played round-robin, top two from each group advance to an 8-team knockout.
  - You only ever play the matches involving your own team — every other fixture is simulated instantly using the same rules, so the tournament always advances.

Every ball played is saved to the database immediately, so any in-progress match or World Cup can be picked up again later from the **Resume** list on the home screen — no explicit "save" step needed.

## Tech stack

- **PHP** (PDO + MySQL) for all server logic — no framework
- **MySQL / MariaDB** for persistence (matches, innings, ball-by-ball history, tournaments, fixtures)
- **Vanilla JavaScript** for the stopwatch/reflex mechanic and AJAX polling (online multiplayer, toss, waiting screens)
- **XML** for the seed data: 16 international teams with 11-player rosters, imported into MySQL on first run
- **HTML/CSS**, no build step — just a PHP-capable web server

## Project structure

```
config/         Database connection + schema (auto-created/migrated on first request)
data/           teams.xml — team & player roster seed data
scripts/        import_teams.php — XML → MySQL importer (runs automatically once)
includes/       Shared PHP: auth/session helpers, page header/footer, game engine
auth/           Register / login / logout
api/            JSON endpoints: play a ball, call the toss, poll match state, join/create matches
assets/         CSS and JS (stopwatch, play screen polling, toss, waiting room)
setup_*.php     Match/tournament setup screens for each mode
play.php        The actual game screen (toss → innings → hit-the-stopwatch)
worldcup.php    Tournament bracket / group standings view
scorecard.php   Post-match summary
index.php       Home screen: pick a mode or resume a saved game
```

## Getting started (XAMPP)

1. Copy this folder into your XAMPP `htdocs` directory as `cricket` (i.e. `htdocs/cricket`).
2. Start **Apache** and **MySQL** from the XAMPP control panel.
3. Visit `http://localhost/cricket/` in your browser.

That's it — the database (`cricket_game`), all tables, and the team/player data import themselves automatically on first load. Default connection assumes MySQL on `localhost` with user `root` and no password (standard XAMPP defaults); edit `config/db.php` if your setup differs.

## Requirements

- PHP 8.0+ with PDO MySQL and SimpleXML extensions (both enabled by default in XAMPP)
- MySQL or MariaDB
