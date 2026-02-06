"""Docker-based sandbox for running untrusted bot code in isolated containers.

Provides the same interface as House/Jail in sandbox.py but runs commands
inside ephemeral Docker containers with resource limits and no network access.
"""
import logging
import os
import subprocess
import time
import uuid
from threading import Thread

try:
    from queue import Queue, Empty
except ImportError:
    from Queue import Queue, Empty

log = logging.getLogger('worker')

# Default Docker image for bot execution
DEFAULT_IMAGE = os.environ.get('BOT_BASE_IMAGE', 'bot-base:latest')

# Resource limits
RUN_MEMORY_LIMIT = os.environ.get('BOT_RUN_MEMORY', '256m')
BUILD_MEMORY_LIMIT = os.environ.get('BOT_BUILD_MEMORY', '512m')
CPU_LIMIT = os.environ.get('BOT_CPU_LIMIT', '1.0')
PIDS_LIMIT = os.environ.get('BOT_PIDS_LIMIT', '64')
TMP_SIZE = os.environ.get('BOT_TMP_SIZE', '10m')

# Volume names used by docker-compose (for mapping paths inside worker
# container to named volumes that can be shared with bot containers)
VOLUME_MAP = {}

def _init_volume_map():
    """Build a mapping from mount points inside the worker to Docker volume names.

    We parse /proc/mounts (or the DOCKER_VOLUME_MAP env var) to figure out
    which named volumes are mounted where, so we can re-mount them into
    bot containers.
    """
    global VOLUME_MAP

    # Allow explicit configuration via environment
    # Format: "/var/aichallenge/compiled=compiled_data,/var/aichallenge/uploads=uploads_data"
    env_map = os.environ.get('DOCKER_VOLUME_MAP', '')
    if env_map:
        for entry in env_map.split(','):
            if '=' in entry:
                mount_point, volume_name = entry.split('=', 1)
                VOLUME_MAP[mount_point.strip()] = volume_name.strip()
        return

    # Fallback: try to auto-detect from docker-compose volume names
    # These are the standard volume names from docker-compose.yml
    VOLUME_MAP = {
        '/var/aichallenge/compiled': os.environ.get('DOCKER_VOLUME_COMPILED', 'aichallenge_compiled_data'),
        '/var/aichallenge/uploads': os.environ.get('DOCKER_VOLUME_UPLOADS', 'aichallenge_uploads_data'),
        '/var/aichallenge/maps': os.environ.get('DOCKER_VOLUME_MAPS', 'aichallenge_maps_data'),
    }

_init_volume_map()


def _monitor_output(stream, queue):
    """Monitor a stream (stdout/stderr) and put lines into a queue."""
    try:
        for line in iter(stream.readline, ''):
            if not line:
                break
            line = line.rstrip('\r\n')
            queue.put(line)
    except (ValueError, OSError):
        pass
    finally:
        queue.put(None)


def _resolve_volume_mount(host_path, read_only=True):
    """Convert a path inside the worker container to a Docker volume mount spec.

    Since we're running inside a Docker container ourselves, we can't use
    bind mounts with our internal paths. Instead, we mount the same named
    volumes that the worker uses, and adjust the container path accordingly.

    Returns (volume_spec, container_path) or None if no mapping found.
    """
    for mount_point, volume_name in VOLUME_MAP.items():
        if host_path.startswith(mount_point):
            # Calculate relative path within the volume
            rel_path = os.path.relpath(host_path, mount_point)
            container_mount = mount_point  # Mount at same path in bot container
            ro_flag = ':ro' if read_only else ''
            volume_spec = '%s:%s%s' % (volume_name, container_mount, ro_flag)
            container_path = os.path.join(container_mount, rel_path)
            return volume_spec, container_path
    return None


class DockerSandbox:
    """Sandbox that runs commands in ephemeral Docker containers.

    Implements the same interface as House/Jail for use by engine.py
    and compiler.py.

    Modes:
        "run"   - For game execution. Read-only mount, strict limits,
                  no network, minimal writable space.
        "build" - For compilation. Read-write mount, more memory,
                  longer timeout, still no network.
    """

    def __init__(self, working_directory, mode="run", image=None):
        self.working_directory = working_directory
        self.mode = mode
        self.image = image or DEFAULT_IMAGE
        self.container_name = None
        self.command_process = None
        self._is_alive = False
        self.stdout_queue = Queue()
        self.stderr_queue = Queue()
        self.child_queue = Queue()

    @property
    def is_alive(self):
        """Indicates whether a command is currently running in the sandbox."""
        if self._is_alive:
            sub_result = self.command_process.poll()
            if sub_result is None:
                return True
            self.child_queue.put(None)
            self._is_alive = False
        return False

    def _build_docker_cmd(self, shell_command):
        """Build the docker run command with appropriate flags."""
        # Generate unique container name
        short_id = uuid.uuid4().hex[:8]
        prefix = 'build' if self.mode == 'build' else 'bot'
        self.container_name = '%s-%s' % (prefix, short_id)

        cmd = [
            'docker', 'run',
            '-i',                   # Interactive (connect stdin)
            '--rm',                 # Auto-remove on exit
            '--name', self.container_name,
            '--network=none',       # No network access
            '--pids-limit', PIDS_LIMIT,
            '--security-opt', 'no-new-privileges',
            '--cap-drop=ALL',
        ]

        if self.mode == 'build':
            cmd.extend([
                '--memory', BUILD_MEMORY_LIMIT,
                '--cpus', CPU_LIMIT,
                '--tmpfs', '/tmp:size=50m',
            ])
        else:
            cmd.extend([
                '--memory', RUN_MEMORY_LIMIT,
                '--cpus', CPU_LIMIT,
                '--read-only',
                '--tmpfs', '/tmp:size=%s' % TMP_SIZE,
            ])

        # Set up volume mounts
        read_only = (self.mode == 'run')
        volume_info = _resolve_volume_mount(self.working_directory, read_only=read_only)

        if volume_info:
            volume_spec, container_path = volume_info
            cmd.extend(['-v', volume_spec])
            cmd.extend(['-w', container_path])
        else:
            # Fallback: try direct bind mount (works when not in Docker)
            ro_flag = ':ro' if read_only else ''
            cmd.extend(['-v', '%s:/bot%s' % (self.working_directory, ro_flag)])
            cmd.extend(['-w', '/bot'])

        # Run as non-root user
        cmd.extend(['--user', 'botuser'])

        # Image and command
        cmd.append(self.image)
        cmd.extend(['sh', '-c', shell_command])

        return cmd

    def start(self, shell_command):
        """Start a command running in the sandbox."""
        if self.is_alive:
            from sandbox import SandboxError
            raise SandboxError("Tried to run command with one in progress.")

        docker_cmd = self._build_docker_cmd(shell_command)
        log.info("Starting Docker sandbox: %s (image=%s, mode=%s)" %
                 (self.container_name, self.image, self.mode))
        log.debug("Docker command: %s" % ' '.join(docker_cmd))

        try:
            self.command_process = subprocess.Popen(
                docker_cmd,
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                universal_newlines=True,
            )
        except OSError as e:
            from sandbox import SandboxError
            raise SandboxError('Failed to start Docker container: %s' % e)

        self._is_alive = True

        # Start monitor threads for stdout and stderr
        stdout_monitor = Thread(target=_monitor_output,
                                args=(self.command_process.stdout, self.stdout_queue))
        stdout_monitor.daemon = True
        stdout_monitor.start()

        stderr_monitor = Thread(target=_monitor_output,
                                args=(self.command_process.stderr, self.stderr_queue))
        stderr_monitor.daemon = True
        stderr_monitor.start()

        # Start writer thread
        Thread(target=self._child_writer, daemon=True).start()

    def _child_writer(self):
        """Write queued data to the container's stdin."""
        queue = self.child_queue
        stdin = self.command_process.stdin
        while True:
            ln = queue.get()
            if ln is None:
                break
            try:
                stdin.write(ln)
                stdin.flush()
            except (OSError, IOError):
                self.kill()
                break

    def kill(self):
        """Stop the sandbox and clean up."""
        if not self._is_alive and self.command_process is None:
            return

        # Kill the container
        if self.container_name:
            try:
                subprocess.run(
                    ['docker', 'kill', self.container_name],
                    capture_output=True, timeout=5
                )
            except (subprocess.TimeoutExpired, OSError):
                pass

        # Wait for the subprocess to finish
        if self.command_process:
            try:
                self.command_process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                try:
                    self.command_process.kill()
                except OSError:
                    pass
            self.child_queue.put(None)

        self._is_alive = False

    def pause(self):
        """Pause the container using cgroups freezer (docker pause)."""
        if not self.container_name:
            return
        try:
            subprocess.run(
                ['docker', 'pause', self.container_name],
                capture_output=True, timeout=5
            )
        except (subprocess.TimeoutExpired, OSError):
            pass

    def resume(self):
        """Resume the container (docker unpause)."""
        if not self.container_name:
            return
        try:
            subprocess.run(
                ['docker', 'unpause', self.container_name],
                capture_output=True, timeout=5
            )
        except (subprocess.TimeoutExpired, OSError):
            pass

    def retrieve(self):
        """Copy working directory back out of the sandbox.

        In build mode, files are written directly via the rw mount,
        so this is a no-op. In run mode, the mount is read-only,
        so there's nothing to retrieve.
        """
        if self.is_alive:
            from sandbox import SandboxError
            raise SandboxError("Tried to retrieve sandbox while still alive")

    def release(self):
        """Release the sandbox, ensuring the container is removed."""
        if self.is_alive:
            from sandbox import SandboxError
            raise SandboxError("Sandbox released while still alive")

        # Force remove the container (safety net, --rm should handle it)
        if self.container_name:
            try:
                subprocess.run(
                    ['docker', 'rm', '-f', self.container_name],
                    capture_output=True, timeout=10
                )
            except (subprocess.TimeoutExpired, OSError):
                pass

    def write(self, data):
        """Write string to stdin of the process running in the container."""
        if not self.is_alive:
            return False
        self.child_queue.put(data)

    def write_line(self, line):
        """Write line to stdin (appends newline)."""
        if not self.is_alive:
            return False
        self.child_queue.put(line + "\n")

    def read_line(self, timeout=0):
        """Read line from container's stdout.

        Returns None if no line available within timeout.
        """
        if not self.is_alive:
            timeout = 0
        try:
            return self.stdout_queue.get(block=True, timeout=timeout)
        except Empty:
            return None

    def read_error(self, timeout=0):
        """Read line from container's stderr.

        Returns None if no line available within timeout.
        """
        if not self.is_alive:
            timeout = 0
        try:
            return self.stderr_queue.get(block=True, timeout=timeout)
        except Empty:
            return None

    def check_path(self, path, errors):
        """Check if a file exists in the working directory."""
        resolved_path = os.path.join(self.working_directory, path)
        if not os.path.exists(resolved_path):
            errors.append("Output file " + str(path) + " was not created.")
            return False
        return True


def cleanup_stale_containers():
    """Remove any orphaned bot/build containers from previous runs.

    Call this at worker startup to clean up containers that may have
    been left behind if the worker crashed.
    """
    try:
        result = subprocess.run(
            ['docker', 'ps', '-a', '--filter', 'name=^bot-', '--filter', 'name=^build-',
             '--format', '{{.Names}}'],
            capture_output=True, text=True, timeout=10
        )
        if result.returncode == 0 and result.stdout.strip():
            containers = result.stdout.strip().split('\n')
            for container in containers:
                container = container.strip()
                if container.startswith(('bot-', 'build-')):
                    log.info("Cleaning up stale container: %s" % container)
                    subprocess.run(
                        ['docker', 'rm', '-f', container],
                        capture_output=True, timeout=10
                    )
    except (subprocess.TimeoutExpired, OSError) as e:
        log.warning("Failed to clean up stale containers: %s" % e)
