FROM node:20-slim

RUN useradd -m -s /bin/bash -u 1001 botuser

WORKDIR /bot
