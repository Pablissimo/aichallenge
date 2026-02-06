#!/bin/bash
set -e

CONTEST_ROOT="${CONTEST_ROOT:-/var/aichallenge}"

# Generate worker server_info.py from environment variables
cat > /opt/aichallenge/worker/server_info.py <<PYEOF
import os

server_info = {
    "repo_path": "/opt/aichallenge",
    "maps_path": "${CONTEST_ROOT}/maps",
    "compiled_path": "${CONTEST_ROOT}/compiled",
    "logs_path": "${CONTEST_ROOT}/logs",
    "api_base_url": "${API_BASE_URL:-http://website/}",
    "api_key": "${API_KEY:-worker_api_key_local}",
    "secure_jail": False,
    "memory_limit": ${MEMORY_LIMIT:-1500},
    "game_options": {
        "turns": 1500,
        "loadtime": 3000,
        "turntime": 500,
        "viewradius2": 77,
        "attackradius2": 5,
        "spawnradius2": 1,
        "location": "${API_BASE_URL:-http://website/}",
        "serial": 2,
        "food_rate": (5, 11),
        "food_turn": (19, 37),
        "food_start": (75, 175),
        "food_visible": (3, 5),
        "food": "symmetric",
        "attack": "focus",
        "kill_points": 2,
        "cutoff_turn": 150,
        "cutoff_percent": 0.85
    }
}
PYEOF

# Also generate manager server_info.py (for TrueSkill updates)
cat > /opt/aichallenge/manager/server_info.py <<PYEOF
import os

server_info = {
    "db_username": "${MYSQL_USER:-aichallenge}",
    "db_password": "${MYSQL_PASSWORD:-aichallengepass}",
    "db_name": "${MYSQL_DATABASE:-aichallenge}",
    "db_host": "${MYSQL_HOST:-mysql}",
    "maps_path": "${CONTEST_ROOT}/maps",
    "uploads_path": "${CONTEST_ROOT}/uploads",
    "logs_path": "${CONTEST_ROOT}/logs"
}
PYEOF

# Ensure directories exist
mkdir -p "${CONTEST_ROOT}/uploads" "${CONTEST_ROOT}/maps" "${CONTEST_ROOT}/compiled" \
         "${CONTEST_ROOT}/replays" "${CONTEST_ROOT}/logs"

# Copy maps from the ants distribution to the shared maps volume if not already present
if [ -z "$(ls -A ${CONTEST_ROOT}/maps 2>/dev/null)" ]; then
    echo "Copying maps to ${CONTEST_ROOT}/maps..."
    cp -r /opt/aichallenge/ants/maps/* "${CONTEST_ROOT}/maps/" 2>/dev/null || true
fi

# Copy submission test map
mkdir -p /opt/aichallenge/ants/submission_test
if [ -f /opt/aichallenge/ants/submission_test/test.map ]; then
    echo "Test map already exists"
else
    # Use a small 2-player map for testing
    find "${CONTEST_ROOT}/maps" -name "*p02*" -type f | head -1 | xargs -I{} cp {} /opt/aichallenge/ants/submission_test/test.map 2>/dev/null || true
fi

# Wait for MySQL to be available
echo "Waiting for MySQL to become available..."
for i in $(seq 1 30); do
    if python -c "import pymysql; pymysql.connect(host='${MYSQL_HOST:-mysql}', user='${MYSQL_USER:-aichallenge}', password='${MYSQL_PASSWORD:-aichallengepass}', database='${MYSQL_DATABASE:-aichallenge}')" 2>/dev/null; then
        echo "MySQL is available."
        break
    fi
    echo "  MySQL not ready, waiting... ($i/30)"
    sleep 3
done

# Wait for the website to be available
echo "Waiting for website to become available..."
until curl -sf http://website/ > /dev/null 2>&1; do
    echo "  website not ready, waiting..."
    sleep 3
done
echo "Website is available."

# Load maps into the database
echo "Loading maps into database..."
cd /opt/aichallenge/manager
python add_maps_to_database.py || echo "Warning: failed to load maps into database"

# Copy submission test files to the compiled volume so Docker sandbox can access them
if [ "${USE_DOCKER_SANDBOX}" = "true" ]; then
    echo "Copying submission test files to shared volume..."
    mkdir -p "${CONTEST_ROOT}/compiled/_testbot"
    cp -r /opt/aichallenge/ants/submission_test/* "${CONTEST_ROOT}/compiled/_testbot/" 2>/dev/null || true
fi

# Clean up any stale bot/build containers from previous runs
if [ "${USE_DOCKER_SANDBOX}" = "true" ]; then
    echo "Cleaning up stale bot containers..."
    docker ps -a --filter "name=^bot-" --filter "name=^build-" --format '{{.Names}}' 2>/dev/null | \
        xargs -r docker rm -f 2>/dev/null || true
fi

# Start the worker
echo "Starting worker..."
cd /opt/aichallenge/worker
exec python worker.py -t -n 0
