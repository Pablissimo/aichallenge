FROM mcr.microsoft.com/dotnet/runtime:8.0-bookworm-slim

RUN useradd -m -s /bin/bash -u 1001 botuser

WORKDIR /bot
