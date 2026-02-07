FROM eclipse-temurin:17-jre-jammy

RUN useradd -m -s /bin/bash -u 1001 botuser

WORKDIR /bot
