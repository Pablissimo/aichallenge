#!/bin/bash
# Build the bot-base Docker image used for running bots in isolated containers.
# This must be built before starting the worker with USE_DOCKER_SANDBOX=true.

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

echo "Building bot-base image..."
docker build -t bot-base:latest -f "$SCRIPT_DIR/Dockerfile" "$PROJECT_ROOT"
echo "Done. bot-base:latest is ready."
