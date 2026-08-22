<?php
/**
 * Gettext template extractor for the `arvan-reseller` text domain.
 *
 * WHY hand-rolled instead of `wp i18n make-pot`: WP-CLI is not a dependency of
 * this repository and CI runs without network access, so the catalog has to be
 * reproducible from plain PHP. WHY the token stream and not a regex: the UI
 * copy is Persian and contains apostrophes and escaped quotes — a regex scanner
 * splits those literals in the wrong place and silently truncates msgids.
 *
 * Usage:
 *   php bin/make-pot.php [output.pot] [--po languages/arvan-reseller-fa_IR.po]
 *
 * Exit code 0 on success, 1 on a usage/IO error. Prints the string count.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "make-pot.php is a CLI tool.\n");
    exit(1);
}

require_once __DIR__ . '/gettext.php';

$domain  = 'arvan-reseller';
$root    = str_replace('\\', '/', dirname(__DIR__));
$out     = $root . '/languages/' . $domain . '.pot';
$merge   = array();
$version = '1.0.0';

// `--po <file>` also refreshes a locale catalog from the freshly extracted
// strings, which is the step that would otherwise need msgmerge.
for ($a = 1; $a < $argc; $a++) {
    if ($argv[$a] === '--po' && isset($argv[$a + 1])) {
        $merge[] = str_replace('\\', '/', $argv[++$a]);
    } elseif (strpos($argv[$a], '--po=') === 0) {
        $merge[] = str_replace('\\', '/', substr($argv[$a], 5));
    } elseif (strpos($argv[$a], '--') === 0) {
        fwrite(STDERR, "Usage: php bin/make-pot.php [output.pot] [--po <catalog.po>]\n");
        exit(1);
    } else {
        $out = str_replace('\\', '/', $argv[$a]);
    }
}

// Keep in step with the plugin header; a stale Project-Id-Version is the usual
// reason a translation platform refuses to merge an updated template.
$header = @file_get_contents($root . '/arvan-reseller.php');
if (is_string($header) && preg_match('/^\s*\*\s*Version:\s*(\S+)/mi', $header, $m) === 1) {
    $version = $m[1];
}

/**
 * Argument positions per translation function. All zero-based.
 * 'text' = singular, 'plural', 'context', 'domain'.
 */
$specs = [
    '__'         => ['text' => 0, 'domain' => 1],
    '_e'         => ['text' => 0, 'domain' => 1],
    'esc_html__' => ['text' => 0, 'domain' => 1],
    'esc_html_e' => ['text' => 0, 'domain' => 1],
    'esc_attr__' => ['text' => 0, 'domain' => 1],
    'esc_attr_e' => ['text' => 0, 'domain' => 1],
    '_n'         => ['text' => 0, 'plural' => 1, 'domain' => 3],
    '_x'         => ['text' => 0, 'context' => 1, 'domain' => 2],
    '_nx'        => ['text' => 0, 'plural' => 1, 'context' => 3, 'domain' => 4],
];

/**
 * Every .php file under the given directories, plus the explicit extra files.
 *
 * @param string[] $dirs
 * @param string[] $extra
 * @return string[] absolute paths, sorted so the POT is byte-stable
 */
function arvrs_php_files(array $dirs, array $extra)
{
    $files = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
    }
    foreach ($extra as $file) {
        if (is_file($file)) {
            $files[] = str_replace('\\', '/', $file);
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

/**
 * Decode one PHP string literal token into its raw runtime bytes.
 * token_get_all only yields T_CONSTANT_ENCAPSED_STRING for literals without
 * interpolation, so this never has to deal with `$var` expansion.
 *
 * @param string $token
 * @return string
 */
function arvrs_literal_value($token)
{
    $quote = $token[0];
    $body  = substr($token, 1, -1);

    if ($quote === "'") {
        return str_replace(array('\\\\', "\\'"), array('\\', "'"), $body);
    }

    $out = '';
    $len = strlen($body);
    for ($i = 0; $i < $len; $i++) {
        $c = $body[$i];
        if ($c !== '\\' || $i + 1 >= $len) {
            $out .= $c;
            continue;
        }
        $n = $body[++$i];
        switch ($n) {
            case 'n':
                $out .= "\n";
                break;
            case 't':
                $out .= "\t";
                break;
            case 'r':
                $out .= "\r";
                break;
            case 'v':
                $out .= "\v";
                break;
            case 'f':
                $out .= "\f";
                break;
            case 'e':
                $out .= "\033";
                break;
            case '\\':
                $out .= '\\';
                break;
            case '$':
                $out .= '$';
                break;
            case '"':
                $out .= '"';
                break;
            case 'x':
                if (preg_match('/^[0-9A-Fa-f]{1,2}/', substr($body, $i + 1), $m) === 1) {
                    $out .= chr(hexdec($m[0]));
                    $i   += strlen($m[0]);
                } else {
                    $out .= '\\x';
                }
                break;
            case 'u':
                if (preg_match('/^\{([0-9A-Fa-f]+)\}/', substr($body, $i + 1), $m) === 1) {
                    $out .= arvrs_utf8($m[1]);
                    $i   += strlen($m[0]);
                } else {
                    $out .= '\\u';
                }
                break;
            default:
                if (preg_match('/^[0-7]{1,3}/', $n . substr($body, $i + 1), $m) === 1) {
                    $out .= chr(octdec($m[0]) & 0xFF);
                    $i   += strlen($m[0]) - 1;
                } else {
                    $out .= '\\' . $n;
                }
        }
    }
    return $out;
}

/**
 * Codepoint (hex) to UTF-8 without requiring ext/intl or ext/mbstring.
 *
 * @param string $hex
 * @return string
 */
function arvrs_utf8($hex)
{
    $cp = (int) hexdec($hex);
    if ($cp < 0x80) {
        return chr($cp);
    }
    if ($cp < 0x800) {
        return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
    }
    if ($cp < 0x10000) {
        return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }
    return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F))
        . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
}

/**
 * Reduce one argument's token slice to a literal string, or null when the
 * argument is an expression — a variable msgid cannot be extracted. Adjacent
 * literals joined with `.` are folded, which is how long copy is wrapped here.
 *
 * @param array $tokens
 * @return string|null
 */
function arvrs_argument_literal(array $tokens)
{
    $value    = '';
    $expect   = 'string';
    $anything = false;
    foreach ($tokens as $t) {
        if (is_array($t) && in_array($t[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            continue;
        }
        if ($expect === 'string') {
            if (!is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                return null;
            }
            $value   .= arvrs_literal_value($t[1]);
            $anything = true;
            $expect   = 'dot';
            continue;
        }
        if ($t !== '.') {
            return null;
        }
        $expect = 'string';
    }
    return ($anything && $expect === 'dot') ? $value : null;
}

$files   = arvrs_php_files(
    array($root . '/src', $root . '/templates'),
    array($root . '/arvan-reseller.php', $root . '/uninstall.php')
);
$entries = array();
$skipped = 0;

foreach ($files as $file) {
    $code = file_get_contents($file);
    if ($code === false) {
        continue;
    }
    $rel    = ltrim(substr($file, strlen($root)), '/');
    $tokens = token_get_all($code);
    $count  = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok) || $tok[0] !== T_STRING || !isset($specs[$tok[1]])) {
            continue;
        }
        $spec = $specs[$tok[1]];
        $line = $tok[2];

        // A method call, a declaration, or `new __(...)` is not a gettext call.
        $prev = null;
        for ($p = $i - 1; $p >= 0; $p--) {
            if (is_array($tokens[$p])
                && in_array($tokens[$p][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }
            $prev = $tokens[$p];
            break;
        }
        if (is_array($prev)
            && in_array($prev[0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW), true)) {
            continue;
        }

        // The next significant token must open the argument list.
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j])
            && in_array($tokens[$j][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
            $j++;
        }
        if ($j >= $count || $tokens[$j] !== '(') {
            continue;
        }

        // Split the argument list on top-level commas.
        $depth = 0;
        $args  = array();
        $cur   = array();
        for ($k = $j; $k < $count; $k++) {
            $t = $tokens[$k];
            if (is_string($t)) {
                if ($t === '(' || $t === '[' || $t === '{') {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                } elseif ($t === ')' || $t === ']' || $t === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $args[] = $cur;
                        break;
                    }
                } elseif ($t === ',' && $depth === 1) {
                    $args[] = $cur;
                    $cur    = array();
                    continue;
                }
            } elseif (is_array($t)
                && in_array($t[0], array(T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES), true)) {
                $depth++;
            }
            $cur[] = $t;
        }

        // Domain gate: the argument must be absent, or literally our domain.
        if (isset($args[$spec['domain']])) {
            $arg_domain = arvrs_argument_literal($args[$spec['domain']]);
            if ($arg_domain !== $domain) {
                continue;
            }
        }

        $msgid = isset($args[$spec['text']]) ? arvrs_argument_literal($args[$spec['text']]) : null;
        if ($msgid === null || $msgid === '') {
            $skipped++;
            continue;
        }
        $plural = null;
        if (isset($spec['plural']) && isset($args[$spec['plural']])) {
            $plural = arvrs_argument_literal($args[$spec['plural']]);
        }
        $context = null;
        if (isset($spec['context']) && isset($args[$spec['context']])) {
            $context = arvrs_argument_literal($args[$spec['context']]);
        }

        // A `/* translators: … */` comment sitting on the call travels with the
        // entry; it is the only guidance a translator gets on placeholder order.
        $comment = null;
        for ($p = $i - 1; $p >= 0 && $p >= $i - 6; $p--) {
            if (!is_array($tokens[$p])) {
                break;
            }
            if ($tokens[$p][0] === T_WHITESPACE) {
                continue;
            }
            if (in_array($tokens[$p][0], array(T_COMMENT, T_DOC_COMMENT), true)
                && stripos($tokens[$p][1], 'translators:') !== false) {
                $comment = preg_replace('#^(/\*+|//|\#+)|\*+/$#', '', $tokens[$p][1]);
                $comment = trim(preg_replace('/^\s*\*\s?/m', '', $comment));
            }
            break;
        }

        $key = ($context === null ? '' : $context . "\x04") . $msgid
            . ($plural === null ? '' : "\x00" . $plural);

        if (!isset($entries[$key])) {
            $entries[$key] = array(
                'context'  => $context,
                'msgid'    => $msgid,
                'plural'   => $plural,
                'refs'     => array(),
                'comments' => array(),
                'order'    => count($entries),
            );
        }
        $ref = $rel . ':' . $line;
        if (!in_array($ref, $entries[$key]['refs'], true)) {
            $entries[$key]['refs'][] = $ref;
        }
        if ($comment !== null && !in_array($comment, $entries[$key]['comments'], true)) {
            $entries[$key]['comments'][] = $comment;
        }
    }
}

// Stable order: first appearance in the sorted file walk.
uasort($entries, function (array $a, array $b) {
    return $a['order'] - $b['order'];
});


/**
 * Render a catalog. $translate receives an entry and returns the msgstr list
 * (one element, or one per plural form); an empty list means untranslated.
 *
 * @param array    $entries
 * @param string[] $header_lines already-formatted `"Key: value\n"` lines
 * @param callable $translate
 * @return string
 */
function arvrs_render(array $entries, array $header_lines, callable $translate)
{
    $po  = '# Copyright (C) ' . gmdate('Y') . " Arvan Reseller Team\n";
    $po .= "# This file is distributed under the same license as the Arvan Reseller Platform plugin.\n";
    $po .= "msgid \"\"\n";
    $po .= "msgstr \"\"\n";
    foreach ($header_lines as $line) {
        $po .= '"' . $line . '\n"' . "\n";
    }

    foreach ($entries as $entry) {
        $po .= "\n";
        foreach ($entry['comments'] as $comment) {
            $po .= '#. ' . str_replace("\n", ' ', $comment) . "\n";
        }
        foreach ($entry['refs'] as $ref) {
            $po .= '#: ' . $ref . "\n";
        }
        // WHY the flag: msgfmt refuses a translation whose placeholder set
        // differs from the msgid's, which is the check that catches a
        // translator dropping a %s from a money or count string.
        $probe = $entry['msgid'] . (string) $entry['plural'];
        if (preg_match('/%(\d+\$)?[+\-]?[0-9.\']*[bcdeEfFgGosuxX]/', $probe) === 1) {
            $po .= "#, php-format\n";
        }
        if ($entry['context'] !== null) {
            $po .= arvrs_po_field('msgctxt', $entry['context']);
        }
        $po .= arvrs_po_field('msgid', $entry['msgid']);

        $msgstr = call_user_func($translate, $entry);
        if ($entry['plural'] !== null) {
            $po .= arvrs_po_field('msgid_plural', $entry['plural']);
            $po .= arvrs_po_field('msgstr[0]', isset($msgstr[0]) ? $msgstr[0] : '');
            $po .= arvrs_po_field('msgstr[1]', isset($msgstr[1]) ? $msgstr[1] : '');
        } else {
            $po .= arvrs_po_field('msgstr', isset($msgstr[0]) ? $msgstr[0] : '');
        }
    }
    return $po;
}

/**
 * Write $content to $path, creating the directory. Exits non-zero on failure —
 * a half-written catalog is worse than no catalog.
 *
 * @param string $path
 * @param string $content
 * @return void
 */
function arvrs_write($path, $content)
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fwrite(STDERR, 'Cannot create ' . $dir . "\n");
        exit(1);
    }
    if (file_put_contents($path, $content) === false) {
        fwrite(STDERR, 'Cannot write ' . $path . "\n");
        exit(1);
    }
}

$now = gmdate('Y-m-d H:iO');

arvrs_write($out, arvrs_render(
    $entries,
    array(
        'Project-Id-Version: Arvan Reseller Platform ' . $version,
        'Report-Msgid-Bugs-To: https://github.com/successtrade/arvan-reseller/issues',
        'POT-Creation-Date: ' . $now,
        'PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE',
        'Last-Translator: FULL NAME <EMAIL@ADDRESS>',
        'Language-Team: LANGUAGE <LL@li.org>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'Plural-Forms: nplurals=2; plural=(n > 1);',
        'X-Generator: bin/make-pot.php',
        'X-Domain: ' . $domain,
    ),
    function () {
        return array();
    }
));

printf(
    "%s: %d strings from %d files (%d non-literal msgids skipped)\n",
    $out,
    count($entries),
    count($files),
    $skipped
);

foreach ($merge as $po_path) {
    $locale = 'fa_IR';
    if (preg_match('/-([a-z]{2,3}(?:_[A-Za-z]{2,4})?)\.po$/', basename($po_path), $m) === 1) {
        $locale = $m[1];
    }
    $is_source = strpos($locale, 'fa') === 0;

    $existing = is_file($po_path) ? arvrs_po_parse($po_path) : array('header' => '', 'entries' => array());
    $kept     = 0;
    $seeded   = 0;
    $blank    = 0;

    $translate = function (array $entry) use ($existing, $is_source, &$kept, &$seeded, &$blank) {
        $key  = arvrs_po_key($entry['context'], $entry['msgid']);
        $have = isset($existing['entries'][$key]) ? $existing['entries'][$key]['msgstr'] : array();
        $have = array_filter($have, function ($s) {
            return $s !== '';
        });
        if ($have !== array()) {
            $kept++;
            return array_values($have);
        }
        // The shipped source copy IS Persian, so for fa the identity mapping is
        // the real translation, not a placeholder — that is what makes the
        // catalog load and prove itself at runtime rather than silently no-op.
        if ($is_source) {
            $seeded++;
            return $entry['plural'] === null
                ? array($entry['msgid'])
                : array($entry['msgid'], $entry['plural']);
        }
        $blank++;
        return array();
    };

    $header_lines = array(
        'Project-Id-Version: Arvan Reseller Platform ' . $version,
        'Report-Msgid-Bugs-To: https://github.com/successtrade/arvan-reseller/issues',
        'POT-Creation-Date: ' . $now,
        'PO-Revision-Date: ' . $now,
        'Last-Translator: Arvan Reseller Team <successtrade.ir@gmail.com>',
        'Language-Team: Persian (Iran)',
        'Language: ' . $locale,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'Plural-Forms: nplurals=2; plural=(n > 1);',
        'X-Generator: bin/make-pot.php',
        'X-Domain: ' . $domain,
    );
    if (!$is_source) {
        $header_lines[4] = 'Last-Translator: FULL NAME <EMAIL@ADDRESS>';
        $header_lines[5] = 'Language-Team: LANGUAGE <LL@li.org>';
    }

    arvrs_write($po_path, arvrs_render($entries, $header_lines, $translate));
    printf("%s: %d kept, %d seeded from source, %d untranslated\n", $po_path, $kept, $seeded, $blank);
}

exit(0);
