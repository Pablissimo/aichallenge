FROM eclipse-temurin:17-jdk-jammy

RUN useradd -m -s /bin/bash -u 1001 botuser

WORKDIR /bot
