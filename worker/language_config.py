"""Language configuration registry for supported bot languages.

Defines the 4 supported languages with their build/run images,
compilation steps, main file detection, and run commands.
"""

BOT = "MyBot"

# Memory limit for JVM-based languages (in MB)
try:
    from server_info import server_info
    MEMORY_LIMIT = server_info.get('memory_limit', 1500)
except ImportError:
    MEMORY_LIMIT = 1500

LANGUAGES = {
    "Python": {
        "main_code_file": BOT + ".py",
        "out_file": BOT + ".py",
        "build_image": "bot-python:latest",
        "run_image": "bot-python:latest",
        "run_command": "python3 MyBot.py",
        "nukeglobs": ["*.pyc"],
        "build_steps": [],  # Interpreted — no compilation
    },
    "Java": {
        "main_code_file": BOT + ".java",
        "out_file": BOT + ".jar",
        "build_image": "bot-java-build:latest",
        "run_image": "bot-java:latest",
        "run_command": "java -Xmx%sm -jar MyBot.jar" % MEMORY_LIMIT,
        "nukeglobs": ["*.class", "*.jar"],
        "build_steps": [
            ("*.java", "javac -J-Xmx%sm *.java" % MEMORY_LIMIT),
            ("*.class", "jar cfe MyBot.jar MyBot *.class"),
        ],
    },
    "CSharp": {
        "main_code_file": BOT + ".cs",
        "out_file": BOT + ".dll",
        "build_image": "bot-csharp-build:latest",
        "run_image": "bot-csharp:latest",
        "run_command": "dotnet MyBot.dll",
        "nukeglobs": ["*.dll", "*.exe", "obj", "bin"],
        "build_steps": [
            # Auto-generate .csproj then build
            ("*.cs", "sh -c 'if [ ! -f *.csproj ]; then dotnet new console -n MyBot --no-restore --force -o . && rm -f Program.cs; fi && dotnet build -c Release -o . --nologo -v q'"),
        ],
    },
    "Javascript": {
        "main_code_file": BOT + ".js",
        "out_file": BOT + ".js",
        "build_image": "bot-javascript:latest",
        "run_image": "bot-javascript:latest",
        "run_command": "node MyBot.js",
        "nukeglobs": [],
        "build_steps": [],  # Interpreted — no compilation
    },
}


def detect_language(bot_dir):
    """Detect the language of a submission by checking for main code files.

    Returns (language_name, config_dict, errors).
    On success errors is None; on failure language_name and config are None.
    """
    import os
    matches = []
    for name, config in LANGUAGES.items():
        if os.path.exists(os.path.join(bot_dir, config["main_code_file"])):
            matches.append((name, config))

    if len(matches) == 1:
        name, config = matches[0]
        return name, config, None
    elif len(matches) > 1:
        files = ", ".join(c["main_code_file"] for _, c in matches)
        return None, None, ["Found multiple MyBot.* files: " + files]
    else:
        supported = "\n".join(
            "%s: %s" % (name, cfg["main_code_file"])
            for name, cfg in LANGUAGES.items()
        )
        return None, None, [
            "Did not find a recognized MyBot.* file.\n"
            "Supported languages:\n" + supported
        ]
