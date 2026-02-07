FROM mcr.microsoft.com/dotnet/sdk:8.0-bookworm-slim

RUN useradd -m -s /bin/bash -u 1001 botuser

WORKDIR /bot
