-- Cricket Game database schema
-- Database creation/selection is handled dynamically by config/db.php — this
-- file only defines tables, so it works against whatever database db.php connected to.

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(30) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL UNIQUE,
    short_code VARCHAR(5) NOT NULL,
    tier ENUM('top10','other') NOT NULL DEFAULT 'other'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS players (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    name VARCHAR(60) NOT NULL,
    batting_order TINYINT NOT NULL,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS matches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mode ENUM('single','online','local','worldcup') NOT NULL,
    status ENUM('waiting','toss_call','toss','in_progress','completed') NOT NULL DEFAULT 'waiting',
    team1_id INT NOT NULL,
    team2_id INT NULL,
    user1_id INT NOT NULL,
    user2_id INT NULL,
    player1_name VARCHAR(40) NULL,
    player2_name VARCHAR(40) NULL,
    overs_limit TINYINT NOT NULL DEFAULT 5,
    wickets_limit TINYINT NOT NULL DEFAULT 3,
    toss_caller_team_id INT NULL,
    toss_call ENUM('heads','tails') NULL,
    toss_flip_result ENUM('heads','tails') NULL,
    toss_winner_team_id INT NULL,
    toss_decision ENUM('bat','bowl') NULL,
    batting_team_innings1 INT NULL,
    current_innings TINYINT NOT NULL DEFAULT 1,
    join_code VARCHAR(8) NULL,
    worldcup_fixture_id INT NULL,
    winner_team_id INT NULL,
    result_text VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (team1_id) REFERENCES teams(id),
    FOREIGN KEY (team2_id) REFERENCES teams(id),
    FOREIGN KEY (user1_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS innings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    match_id INT NOT NULL,
    innings_no TINYINT NOT NULL,
    batting_team_id INT NOT NULL,
    bowling_team_id INT NOT NULL,
    runs INT NOT NULL DEFAULT 0,
    wickets TINYINT NOT NULL DEFAULT 0,
    balls_bowled INT NOT NULL DEFAULT 0,
    target INT NULL,
    completed TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS balls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    innings_id INT NOT NULL,
    over_no TINYINT NOT NULL,
    ball_no TINYINT NOT NULL,
    stopwatch_value TINYINT NOT NULL,
    outcome ENUM('run','dot','wicket') NOT NULL,
    runs_scored TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (innings_id) REFERENCES innings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS worldcups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_team_id INT NOT NULL,
    num_teams TINYINT NOT NULL,
    overs_limit TINYINT NOT NULL DEFAULT 5,
    wickets_limit TINYINT NOT NULL DEFAULT 3,
    stage ENUM('group','knockout','done') NOT NULL DEFAULT 'group',
    champion_team_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS worldcup_teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worldcup_id INT NOT NULL,
    team_id INT NOT NULL,
    group_no TINYINT NULL,
    played TINYINT NOT NULL DEFAULT 0,
    won TINYINT NOT NULL DEFAULT 0,
    lost TINYINT NOT NULL DEFAULT 0,
    runs_for INT NOT NULL DEFAULT 0,
    runs_against INT NOT NULL DEFAULT 0,
    eliminated TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (worldcup_id) REFERENCES worldcups(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS worldcup_fixtures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worldcup_id INT NOT NULL,
    round_name ENUM('group','QF','SF','F') NOT NULL,
    match_id INT NULL,
    team1_id INT NULL,
    team2_id INT NULL,
    team1_score INT NULL,
    team2_score INT NULL,
    winner_team_id INT NULL,
    involves_user TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending','completed','bye') NOT NULL DEFAULT 'pending',
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (worldcup_id) REFERENCES worldcups(id) ON DELETE CASCADE
) ENGINE=InnoDB;
