#!/bin/bash
set -e

# Generate server_info.php from environment variables
cat > /var/www/html/server_info.php <<'PHPEOF'
<?php

$server_info = array(
    "db_host" => getenv('DB_HOST') ?: 'localhost',
    "db_username" => getenv('DB_USERNAME') ?: 'aichallenge',
    "db_password" => getenv('DB_PASSWORD') ?: 'aichallengepass',
    "db_name" => getenv('DB_NAME') ?: 'aichallenge',
    "mailer_address" => "donotsend",
    "aws_accesskey" => "",
    "aws_secretkey" => "",
    "submissions_open" => True,
    "repo_path" => getenv('REPO_PATH') ?: '/var/www/html',
    "uploads_path" => (getenv('CONTEST_ROOT') ?: '/var/aichallenge') . '/uploads',
    "maps_path" => (getenv('CONTEST_ROOT') ?: '/var/aichallenge') . '/maps',
    "replay_path" => (getenv('CONTEST_ROOT') ?: '/var/aichallenge') . '/replays',
    "api_create_key" => "",
    "api_log" => (getenv('CONTEST_ROOT') ?: '/var/aichallenge') . '/logs/php_api.log',
    "game_result_errors" => (getenv('CONTEST_ROOT') ?: '/var/aichallenge') . '/logs/game_result_errors.log',
    "game_options" => array (
        "turns" => 1500,
        "loadtime" => 3000,
        "turntime" => 500,
        "viewradius2" => 77,
        "attackradius2" => 5,
        "spawnradius2" => 1,
        "location" => getenv('API_URL') ?: 'http://localhost:8080/',
        "serial" => 2,
        "food_rate" => array(5,11),
        "food_turn" => array(19,37),
        "food_start" => array(75,175),
        "food_visible" => array(3,5),
        "food" => "symmetric",
        "attack" => "focus",
        "kill_points" => 2,
        "cutoff_turn" => 150,
        "cutoff_percent" => 0.85
    )
);

?>
PHPEOF

# Ensure directories exist with correct permissions
mkdir -p /var/aichallenge/uploads /var/aichallenge/maps /var/aichallenge/replays /var/aichallenge/logs
chown -R www-data:www-data /var/aichallenge
touch /var/aichallenge/logs/php_api.log /var/aichallenge/logs/game_result_errors.log
chown www-data:www-data /var/aichallenge/logs/php_api.log /var/aichallenge/logs/game_result_errors.log

# Start Apache in foreground
exec apache2-foreground
