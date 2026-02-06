FROM python:3.9-slim-bookworm

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    openjdk-17-jdk-headless \
    gcc \
    g++ \
    make \
    curl \
    unzip \
    zip \
    && rm -rf /var/lib/apt/lists/*

# Install Python packages
RUN pip install --no-cache-dir pymysql

# Create app directory structure
RUN mkdir -p /opt/aichallenge/worker \
             /opt/aichallenge/manager \
             /opt/aichallenge/ants \
             /var/aichallenge/uploads \
             /var/aichallenge/maps \
             /var/aichallenge/compiled \
             /var/aichallenge/replays \
             /var/aichallenge/logs

# Copy application code
COPY worker/ /opt/aichallenge/worker/
COPY manager/ /opt/aichallenge/manager/
COPY ants/ /opt/aichallenge/ants/

# Compile TSUpdate Java class for TrueSkill
WORKDIR /opt/aichallenge/manager/jskills
RUN javac -cp JSkills_0.9.0.jar TSUpdate.java || true

# Copy entrypoint
COPY docker/worker/entrypoint.sh /entrypoint.sh
RUN sed -i 's/\r$//' /entrypoint.sh && chmod +x /entrypoint.sh

WORKDIR /opt/aichallenge/worker

ENTRYPOINT ["/entrypoint.sh"]
