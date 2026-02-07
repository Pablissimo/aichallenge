FROM python:3.11-slim-bookworm

RUN useradd -m -s /bin/bash -u 1001 botuser

WORKDIR /bot
