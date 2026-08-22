<?php
/**
 * Build the distributable plugin ZIP.
 *
 * A WordPress user installs a plugin by uploading a ZIP whose single top-level
 * directory is the plugin slug. This repository is a development workspace —
 * it carries tests, CI config, engineering docs, a vendored dev toolchain and
 * review artifacts, none of which belong in a user's wp-content/plugins. So
 * the shipped archive is built from an explicit ALLOW list rather than by
 * excluding things: a deny list silently ships whatever you forgot to name,
 * and "whatever you forgot" is how credentials reach a public download.
 *
 * Usage:  php bin/build-plugin.php [--out=dist] [--slug=arvan-reseller]
 * Exit:   0 built and verified, 1 verification failed, 2 harness problem.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ext-zip is required to build the package\n");
    exit(2);
}

$base = dirname(__DIR__);

/**
 * Everything the plugin needs AT RUNTIME, and nothing else. Directories are
 * taken whole; files are taken by name. Anything not named here does not ship.
 */
// `data/` holds the bundled bcrypt licence allowlist that License::activate()
// includes at runtime — omit it and the plugin installs but can never be
// activated. Caught by the install-from-ZIP test, which is why that test runs.
const SHIP_DIRS  = ['src', 'templates', 'assets', 'languages', 'data'];
const SHIP_FILES = ['arvan-reseller.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'CHANGELOG.md'];

/** Never ship these, even if they appear inside a shipped directory. */
const NEVER = [
    '.git', '.gitignore', '.gitattributes', '.github', '.DS_Store', 'Thumbs.db',
    'node_modules', 'vendor', '.phpunit.cache', '.playwright-cli', '.impeccable',
];

/** Extensions that are never runtime assets. */
const NEVER_EXT = ['po', 'pot', 'map', 'scss', 'less', 'ts', 'log', 'bak', 'orig', 'rej'];

// --------------------------------------------------------------------- args
$opts = getopt('', ['out::', 'slug::']);
$out  = $base . '/' . ($opts['out'] ?? 'dist');
$slug = $opts['slug'] ?? 'arvan-reseller';

// ------------------------------------------------------------------ version
$header = file_get_contents($base . '/' . $slug . '.php');
if ($header === false) {
    fwrite(STDERR, "cannot read $slug.php\n");
    exit(2);
}
if (!preg_match('/^\s*\*\s*Version:\s*(\S+)/mi', $header, $m)) {
    fwrite(STDERR, "no Version header in $slug.php\n");
    exit(2);
}
$version = $m[1];

// The header version and the runtime constant must agree, or an upgrade will
// report one number while the migration gate reads another.
if (!preg_match("/define\(\s*'ARVRS_VERSION'\s*,\s*'([^']+)'/", $header, $c) || $c[1] !== $version) {
    fwrite(STDERR, "Version header ($version) disagrees with ARVRS_VERSION (" . ($c[1] ?? 'missing') . ")\n");
    exit(1);
}

// readme.txt's Stable tag is what wordpress.org serves; a mismatch ships the
// wrong version to every existing install.
$readme = @file_get_contents($base . '/readme.txt');
if ($readme !== false && preg_match('/^Stable tag:\s*(\S+)/mi', $readme, $s) && $s[1] !== $version) {
    fwrite(STDERR, "readme.txt Stable tag ({$s[1]}) disagrees with Version ($version)\n");
    exit(1);
}

// ---------------------------------------------------------------- collection
/** @return string[] repo-relative paths */
function collect(string $base): array
{
    $files = [];
    foreach (SHIP_FILES as $f) {
        if (is_file($base . '/' . $f)) {
            $files[] = $f;
        }
    }
    foreach (SHIP_DIRS as $dir) {
        $root = $base . '/' . $dir;
        if (!is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $rel   = str_replace('\\', '/', substr($item->getPathname(), strlen($base) + 1));
            $parts = explode('/', $rel);
            foreach ($parts as $part) {
                if (in_array($part, NEVER, true)) {
                    continue 2;
                }
            }
            if (!$item->isFile()) {
                continue;
            }
            if (in_array(strtolower($item->getExtension()), NEVER_EXT, true)) {
                continue;
            }
            $files[] = $rel;
        }
    }
    sort($files);
    return $files;
}

$files = collect($base);
if (!$files) {
    fwrite(STDERR, "nothing to package\n");
    exit(2);
}

// ------------------------------------------------------- pre-flight refusals
$problems = [];

// A plaintext Plugin Access Token or an Arvan machine-user key in a public
// download is unrecoverable — you cannot un-publish a ZIP.
foreach ($files as $rel) {
    $body = file_get_contents($base . '/' . $rel);
    if ($body === false) {
        continue;
    }
    if (preg_match('/ARVRS-[0-9A-F]{32}/', $body)) {
        $problems[] = "$rel contains a plaintext plugin access token";
    }
    if (preg_match('/Apikey\s+[0-9a-f]{8}-[0-9a-f]{4}-/i', $body)) {
        $problems[] = "$rel contains what looks like an Arvan API key";
    }
}

// The main file must be present and must be a plugin header WordPress accepts.
if (!in_array($slug . '.php', $files, true)) {
    $problems[] = "$slug.php is not in the package";
}
foreach (['Plugin Name', 'Version', 'Requires PHP', 'License', 'Text Domain'] as $required) {
    if (!preg_match('/^\s*\*\s*' . preg_quote($required, '/') . ':/mi', $header)) {
        $problems[] = "plugin header is missing `$required`";
    }
}
// The catalog must ship compiled; a .po alone does nothing at runtime.
if (is_dir($base . '/languages') && !in_array('languages/arvan-reseller-fa_IR.mo', $files, true)) {
    $problems[] = 'languages/ ships without a compiled .mo — translations would not load';
}

if ($problems) {
    fwrite(STDERR, "REFUSING TO BUILD:\n  - " . implode("\n  - ", $problems) . "\n");
    exit(1);
}

// ---------------------------------------------------------------- write zip
if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
    fwrite(STDERR, "cannot create $out\n");
    exit(2);
}
$zip_path = $out . '/' . $slug . '-' . $version . '.zip';
@unlink($zip_path);

$zip = new ZipArchive();
if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "cannot open $zip_path for writing\n");
    exit(2);
}
foreach ($files as $rel) {
    // Single top-level directory named for the slug — this is what makes
    // "Upload Plugin" in wp-admin unpack to wp-content/plugins/<slug>/.
    $zip->addFile($base . '/' . $rel, $slug . '/' . $rel);
}
$zip->close();

// ------------------------------------------------------------ verify the zip
$check = new ZipArchive();
if ($check->open($zip_path) !== true) {
    fwrite(STDERR, "built archive will not reopen\n");
    exit(1);
}
$roots = [];
$bad   = [];
for ($i = 0; $i < $check->numFiles; $i++) {
    $name  = $check->getNameIndex($i);
    $roots[explode('/', $name)[0]] = true;
    foreach (NEVER as $n) {
        if (in_array($n, explode('/', $name), true)) {
            $bad[] = $name;
        }
    }
}
$has_main = $check->locateName($slug . '/' . $slug . '.php') !== false;
$check->close();

if (count($roots) !== 1 || !isset($roots[$slug])) {
    fwrite(STDERR, 'archive must have exactly one top-level dir named ' . $slug . ', found: ' . implode(', ', array_keys($roots)) . "\n");
    exit(1);
}
if (!$has_main) {
    fwrite(STDERR, "archive does not contain $slug/$slug.php\n");
    exit(1);
}
if ($bad) {
    fwrite(STDERR, 'archive contains excluded paths: ' . implode(', ', array_slice($bad, 0, 5)) . "\n");
    exit(1);
}

printf(
    "built %s\n  version : %s\n  files   : %d\n  size    : %s KB\n  sha256  : %s\n",
    str_replace('\\', '/', $zip_path),
    $version,
    count($files),
    number_format(filesize($zip_path) / 1024, 1),
    hash_file('sha256', $zip_path)
);
exit(0);
