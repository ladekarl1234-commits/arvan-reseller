<?php
/**
 * Shared gettext plumbing for the bin/ catalog tools: quoting both ways and a
 * PO reader. Required by make-pot.php and make-mo.php.
 *
 * CLI only — these tools ship inside the plugin directory, so a web request
 * that reaches this path must do nothing at all.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

/**
 * Escape a raw string for the body of a gettext quoted field.
 *
 * @param string $s
 * @return string
 */
function arvrs_po_escape($s)
{
    return str_replace(
        array('\\',   '"',   "\n",  "\t",  "\r"),
        array('\\\\', '\\"', '\\n', '\\t', '\\r'),
        $s
    );
}

/**
 * Inverse of arvrs_po_escape, for the contents of one `"…"` field body.
 *
 * @param string $s
 * @return string
 */
function arvrs_po_unescape($s)
{
    $out = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        if ($s[$i] !== '\\' || $i + 1 >= $len) {
            $out .= $s[$i];
            continue;
        }
        $n = $s[++$i];
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
            case '"':
                $out .= '"';
                break;
            case '\\':
                $out .= '\\';
                break;
            default:
                $out .= $n;
        }
    }
    return $out;
}

/**
 * The lookup key gettext itself uses: context and the singular, joined by EOT.
 *
 * @param string|null $context
 * @param string      $msgid
 * @return string
 */
function arvrs_po_key($context, $msgid)
{
    return ($context === null || $context === '') ? $msgid : $context . "\x04" . $msgid;
}

/**
 * Read a .po/.pot file.
 *
 * Line-oriented on purpose: gettext catalogs are a line format, and the only
 * continuation rule is that a bare `"…"` line appends to the field above it.
 *
 * @param string $path
 * @return array{header:string,entries:array} entries keyed by arvrs_po_key();
 *         each entry is ['context'=>?string,'msgid'=>string,
 *         'plural'=>?string,'msgstr'=>string[],'fuzzy'=>bool]
 */
function arvrs_po_parse($path)
{
    $lines   = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return array('header' => '', 'entries' => array());
    }

    $header  = '';
    $entries = array();
    $entry   = null;
    $field   = null;   // 'msgctxt' | 'msgid' | 'plural' | int index into msgstr
    $fuzzy   = false;

    $flush = function () use (&$entry, &$entries, &$header, &$field, &$fuzzy) {
        if ($entry === null) {
            return;
        }
        if ($entry['msgid'] === '' && $entry['context'] === null) {
            $header = isset($entry['msgstr'][0]) ? $entry['msgstr'][0] : '';
        } else {
            $entry['fuzzy'] = $fuzzy;
            $entries[arvrs_po_key($entry['context'], $entry['msgid'])] = $entry;
        }
        $entry = null;
        $field = null;
        $fuzzy = false;
    };

    foreach ($lines as $line) {
        $trim = trim($line);

        if ($trim === '') {
            $flush();
            continue;
        }
        if ($trim[0] === '#') {
            // Only the fuzzy flag changes how the entry is compiled.
            if (strpos($trim, '#,') === 0 && strpos($trim, 'fuzzy') !== false) {
                $fuzzy = true;
            }
            continue;
        }

        if (preg_match('/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+"(.*)"$/', $trim, $m) === 1) {
            $kind  = $m[1];
            $value = arvrs_po_unescape($m[3]);

            // A catalog written without blank lines between entries is still
            // legal: msgctxt, or a msgid after a msgstr, starts a new entry.
            if ($entry !== null
                && ($kind === 'msgctxt' || ($kind === 'msgid' && $entry['msgstr'] !== array()))) {
                $flush();
            }
            if ($entry === null) {
                $entry = array(
                    'context' => null,
                    'msgid'   => '',
                    'plural'  => null,
                    'msgstr'  => array(),
                    'fuzzy'   => false,
                );
            }

            if ($kind === 'msgctxt') {
                $entry['context'] = $value;
                $field            = 'msgctxt';
            } elseif ($kind === 'msgid') {
                $entry['msgid'] = $value;
                $field          = 'msgid';
            } elseif ($kind === 'msgid_plural') {
                $entry['plural'] = $value;
                $field           = 'plural';
            } else {
                $idx                    = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
                $entry['msgstr'][$idx]  = $value;
                $field                  = $idx;
            }
            continue;
        }

        // Continuation of the field above.
        if (preg_match('/^"(.*)"$/', $trim, $m) === 1 && $entry !== null && $field !== null) {
            $value = arvrs_po_unescape($m[1]);
            if ($field === 'msgctxt') {
                $entry['context'] .= $value;
            } elseif ($field === 'msgid') {
                $entry['msgid'] .= $value;
            } elseif ($field === 'plural') {
                $entry['plural'] .= $value;
            } else {
                $entry['msgstr'][$field] .= $value;
            }
        }
    }
    $flush();

    return array('header' => $header, 'entries' => $entries);
}

/**
 * Render one quoted gettext field. Multi-line values use the `key ""` form so
 * the file stays readable and diffable when copy grows a paragraph.
 *
 * @param string $key
 * @param string $value
 * @return string
 */
function arvrs_po_field($key, $value)
{
    if (strpos($value, "\n") === false) {
        return $key . ' "' . arvrs_po_escape($value) . "\"\n";
    }
    $out   = $key . " \"\"\n";
    $parts = explode("\n", $value);
    $last  = count($parts) - 1;
    foreach ($parts as $i => $part) {
        if ($i === $last && $part === '') {
            break;
        }
        $out .= '"' . arvrs_po_escape($part) . ($i === $last ? '' : '\\n') . "\"\n";
    }
    return $out;
}
