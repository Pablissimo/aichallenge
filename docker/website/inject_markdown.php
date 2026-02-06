<?php
/**
 * Build-time script: converts wiki markdown to HTML fragments and replaces
 * MarkdownReplacement comment tags in PHP files with PHP include calls.
 *
 * Usage: php inject_markdown.php
 *
 * Expects:
 *   - Wiki .md files in /tmp/wiki/
 *   - PHP files in /var/www/html/
 *   - cmark binary available in PATH
 *
 * Produces:
 *   - HTML fragments in /var/www/html/wiki/
 *   - Modified PHP files with <?php @include('wiki/...'); ?> calls
 */

$wikiDir = '/tmp/wiki';
$htmlDir = '/var/www/html/wiki';
$webRoot = '/var/www/html';

// Step 1: Convert all .md files to .html fragments using cmark
echo "Converting markdown files to HTML...\n";
$mdFiles = glob("$wikiDir/*.md");
$converted = 0;
foreach ($mdFiles as $mdFile) {
    $basename = basename($mdFile, '.md');
    $htmlFile = "$htmlDir/$basename.html";
    $cmd = 'cmark ' . escapeshellarg($mdFile);
    $html = shell_exec($cmd);
    if ($html !== null) {
        file_put_contents($htmlFile, $html);
        $converted++;
    } else {
        echo "  WARNING: cmark failed for $mdFile\n";
    }
}
echo "  Converted $converted markdown files.\n";

// Step 2: Replace MarkdownReplacement tags in PHP files with include calls
echo "Replacing MarkdownReplacement tags in PHP files...\n";
$phpFiles = glob("$webRoot/*.php");
$pattern = '/<!--<MarkdownReplacement with="([^"]+)">-->.*?<!--<\/MarkdownReplacement>-->/s';
$totalReplacements = 0;

foreach ($phpFiles as $phpFile) {
    $content = file_get_contents($phpFile);
    if (strpos($content, 'MarkdownReplacement') === false) {
        continue;
    }

    $replacements = 0;
    $newContent = preg_replace_callback($pattern, function ($matches) use ($htmlDir, &$replacements) {
        $mdFilename = $matches[1];
        // Map "competition" to "Ants" as setup.py did
        $resolved = str_replace('competition', 'Ants', $mdFilename);
        // Change .md to .html
        $htmlFilename = preg_replace('/\.md$/', '.html', $resolved);

        $htmlPath = "$htmlDir/$htmlFilename";
        if (!file_exists($htmlPath)) {
            echo "  WARNING: No HTML for $mdFilename (expected $htmlFilename)\n";
        }

        $replacements++;
        return "<?php @include('wiki/$htmlFilename'); ?>";
    }, $content);

    if ($replacements > 0) {
        file_put_contents($phpFile, $newContent);
        echo "  " . basename($phpFile) . ": $replacements replacement(s)\n";
        $totalReplacements += $replacements;
    }
}

echo "Done. $totalReplacements total replacements across all files.\n";
