#!/usr/bin/python
# compiler.py - Simplified compiler supporting 4 languages:
# Python, Java, C#, JavaScript
#
# Auto-detects the language of the entry based on the main code file,
# compiles it (if needed), and writes run.sh with the run command.

import errno
import fnmatch
import os
import re
import sys
import time
from optparse import OptionParser

from sandbox import get_sandbox
from language_config import LANGUAGES, detect_language as _detect_language

SAFEPATH = re.compile('[a-zA-Z0-9_.$-]+$')


class CD(object):
    def __init__(self, new_dir):
        self.new_dir = new_dir

    def __enter__(self):
        self.org_dir = os.getcwd()
        os.chdir(self.new_dir)
        return self.new_dir

    def __exit__(self, type, value, traceback):
        os.chdir(self.org_dir)


def safeglob(pattern):
    safepaths = []
    for root, dirs, files in os.walk("."):
        files = fnmatch.filter(files, pattern)
        for fname in files:
            if SAFEPATH.match(fname):
                safepaths.append(os.path.join(root, fname))
    return safepaths


def nukeglob(pattern):
    paths = safeglob(pattern)
    for path in paths:
        try:
            os.unlink(path)
        except OSError as e:
            if e.errno != errno.ENOENT:
                raise


def _run_cmd(sandbox, cmd, timelimit):
    """Run a command in a sandbox, collecting stdout and stderr."""
    out = []
    errors = []
    sandbox.start(cmd)
    try:
        while sandbox.is_alive and time.time() < timelimit:
            out_ln = sandbox.read_line((timelimit - time.time()) + 1)
            if out_ln:
                out.append(out_ln)
    finally:
        sandbox.kill()
    # capture remaining output
    tmp = sandbox.read_line(1)
    while tmp:
        out.append(tmp)
        tmp = sandbox.read_line(1)

    if time.time() > timelimit:
        errors.append("Compilation timed out with command %s" % (cmd,))
    err_line = sandbox.read_error()
    while err_line is not None:
        errors.append(err_line)
        err_line = sandbox.read_error()
    return out, errors


def compile_steps(lang_config, bot_dir, timelimit):
    """Run the build steps for a language inside a Docker sandbox.

    Returns (success, errors).
    """
    build_steps = lang_config.get("build_steps", [])
    if not build_steps:
        # Interpreted language — just chmod the files
        with CD(bot_dir):
            for f in safeglob("*"):
                try:
                    os.chmod(f, 0o644)
                except Exception:
                    pass
        return True, []

    errors = []
    stop_time = time.time() + timelimit
    build_image = lang_config.get("build_image")

    for globs_pattern, cmd in build_steps:
        box = get_sandbox(bot_dir, mode="build", image=build_image)
        try:
            cmd_out, cmd_errors = _run_cmd(box, cmd, stop_time)
            if cmd_errors:
                errors.extend(cmd_errors)
                return False, errors
            box.retrieve()
        finally:
            box.release()

    # Verify output file exists
    out_file = lang_config.get("out_file", "")
    out_path = os.path.join(bot_dir, out_file)
    if not os.path.exists(out_path):
        errors.append("Output file %s was not created." % out_file)
        return False, errors

    return True, []


def compile_anything(bot_dir, timelimit=600, max_error_len=3072):
    """Autodetect the language of an entry and compile it.

    Returns (language_name, errors).
    errors is None on success, a list of error strings on failure.
    """
    lang_name, lang_config, detect_errors = _detect_language(bot_dir)
    if not lang_config:
        return "Unknown", detect_errors

    # Nuke old build artifacts
    with CD(bot_dir):
        for pattern in lang_config.get("nukeglobs", []):
            nukeglob(pattern)

    compiled, errors = compile_steps(lang_config, bot_dir, timelimit)
    if compiled:
        run_cmd = lang_config["run_command"]
        run_filename = os.path.join(bot_dir, '../run.sh')
        with open(run_filename, 'w') as f:
            f.write('#%s\n%s\n' % (lang_name, run_cmd))
        return lang_name, None
    else:
        # Truncate long error output
        if len(errors) > 0 and sum(map(len, errors)) > max_error_len:
            first_errors = []
            cur_error = 0
            length = len(errors[0])
            while length < (max_error_len / 3):
                first_errors.append(errors[cur_error])
                cur_error += 1
                if cur_error >= len(errors):
                    break
                length += len(errors[cur_error])
            first_errors.append("...")
            length += 3
            end_errors = []
            cur_error = -1
            while length <= max_error_len and abs(cur_error) <= len(errors):
                end_errors.append(errors[cur_error])
                cur_error -= 1
                if abs(cur_error) > len(errors):
                    break
                length += len(errors[cur_error])
            end_errors.reverse()
            errors = first_errors + end_errors
        return lang_name, errors


def get_run_cmd(submission_dir):
    """Get the run command from a compiled submission's run.sh."""
    with CD(submission_dir):
        if os.path.exists('run.sh'):
            with open('run.sh') as f:
                for line in f:
                    if line[0] != '#':
                        return line.rstrip('\r\n')


def get_run_lang(submission_dir):
    """Get the language name from a compiled submission's run.sh."""
    with CD(submission_dir):
        if os.path.exists('run.sh'):
            with open('run.sh') as f:
                for line in f:
                    if line[0] == '#':
                        return line[1:-1]


def get_run_image(lang_name):
    """Get the runtime Docker image for a language."""
    if lang_name in LANGUAGES:
        return LANGUAGES[lang_name].get("run_image")
    return None


def main(argv=sys.argv):
    parser = OptionParser(usage="Usage: %prog [options] [directory]")
    parser.add_option("-j", "--json", action="store_true", dest="json",
            default=False,
            help="Give compilation results in json format")
    options, args = parser.parse_args(argv)
    if len(args) == 1:
        detected_lang, errors = compile_anything(os.getcwd())
    elif len(args) == 2:
        detected_lang, errors = compile_anything(args[1])
    else:
        parser.error("Extra arguments found, use --help for usage")
    if options.json:
        import json
        print(json.dumps([detected_lang, errors]))
    else:
        print("Detected language:", detected_lang)
        if errors is not None and len(errors) != 0:
            for error in errors:
                print(error)

if __name__ == "__main__":
    main()
