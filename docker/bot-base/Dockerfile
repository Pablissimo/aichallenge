FROM python:3.11-slim-bookworm

# Install language runtimes for all supported languages
RUN apt-get update && apt-get install -y --no-install-recommends \
    openjdk-17-jdk-headless \
    gcc \
    g++ \
    make \
    && rm -rf /var/lib/apt/lists/*

# Install Node.js 20
RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    ca-certificates \
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Install .NET 8 runtime and SDK
RUN curl -fsSL https://dot.net/v1/dotnet-install.sh -o /tmp/dotnet-install.sh \
    && chmod +x /tmp/dotnet-install.sh \
    && /tmp/dotnet-install.sh --channel 8.0 --install-dir /usr/share/dotnet \
    && ln -s /usr/share/dotnet/dotnet /usr/bin/dotnet \
    && rm /tmp/dotnet-install.sh

ENV DOTNET_ROOT=/usr/share/dotnet
ENV DOTNET_CLI_TELEMETRY_OPTOUT=1

# Create non-root user for running bots
RUN useradd -m -s /bin/bash -u 1001 botuser

WORKDIR /bot
