#!/bin/bash
# Build all per-language bot images
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "Building bot images..."

docker build -t bot-python:latest   -f "$SCRIPT_DIR/python.Dockerfile"        "$SCRIPT_DIR"
docker build -t bot-java:latest     -f "$SCRIPT_DIR/java.Dockerfile"          "$SCRIPT_DIR"
docker build -t bot-java-build:latest -f "$SCRIPT_DIR/java-build.Dockerfile"  "$SCRIPT_DIR"
docker build -t bot-csharp:latest   -f "$SCRIPT_DIR/csharp.Dockerfile"        "$SCRIPT_DIR"
docker build -t bot-csharp-build:latest -f "$SCRIPT_DIR/csharp-build.Dockerfile" "$SCRIPT_DIR"
docker build -t bot-javascript:latest -f "$SCRIPT_DIR/javascript.Dockerfile"  "$SCRIPT_DIR"

echo "All bot images built successfully."
docker images | grep "^bot-"
