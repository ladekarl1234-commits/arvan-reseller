<?php
/**
 * PHP 7.4 compatibility gate.
 *
 * The plugin advertises PHP 7.4 as its minimum runtime, but `php -l` only ever
 * tells you about the interpreter that is running it — lint under PHP 8 will
 * happily pass code that fatals on 7.4. This parses every file with a real
 * PHP 7.4 grammar (nikic/php-parser, version-pinned) and then walks the AST
 * for the constructs that parse under 7.4 but mean something newer, so the
 * "Requires PHP 7.4" header is a checked claim rather than an assertion.
 *
 * Usage:  php bin/php74-check.php [path ...]      (default: the plugin runtime)
 * Exit:   0 clean, 1 violations found, 2 harness problem.
 */

declare(strict_types=1);

// bin/ ships inside the plugin directory, so a web request that reaches this
// path must do nothing at all — same guard as the other tools here.
if (PHP_SAPI !== 'cli') {
    exit;
}

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "run `composer install` first (nikic/php-parser is a dev dependency)\n");
    exit(2);
}
require $autoload;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

/** Functions introduced after 7.4 that a 7.4 runtime would fatal on. */
const PHP8_FUNCTIONS = [
    'str_contains'    => '8.0',
    'str_starts_with' => '8.0',
    'str_ends_with'   => '8.0',
    'get_debug_type'  => '8.0',
    'array_is_list'   => '8.1',
    'enum_exists'     => '8.1',
    'array_find'      => '8.4',
    'array_any'       => '8.4',
    'array_all'       => '8.4',
];

/** Type names that only exist as declared types after 7.4. */
const PHP8_TYPES = [
    'mixed'  => '8.0',
    'never'  => '8.1',
    'static' => '8.0', // as a *return* type
];

final class Php74Visitor extends NodeVisitorAbstract
{
    /** @var array<int,array{line:int,rule:string,detail:string}> */
    public $violations = [];

    private function flag(Node $node, string $rule, string $detail): void
    {
        $this->violations[] = ['line' => $node->getStartLine(), 'rule' => $rule, 'detail' => $detail];
    }

    public function enterNode(Node $node)
    {
        // Union / intersection types: `int|string $x`, `: A&B`
        if ($node instanceof Node\UnionType) {
            $this->flag($node, 'union-type', 'union types are PHP 8.0+');
        }
        if ($node instanceof Node\IntersectionType) {
            $this->flag($node, 'intersection-type', 'intersection types are PHP 8.1+');
        }

        // Constructor property promotion.
        if ($node instanceof Node\Param && $node->flags !== 0) {
            $this->flag($node, 'promoted-param', 'constructor property promotion is PHP 8.0+');
        }
        // readonly on a property.
        if ($node instanceof Node\Stmt\Property && ($node->flags & Node\Stmt\Class_::MODIFIER_READONLY) !== 0) {
            $this->flag($node, 'readonly', 'readonly properties are PHP 8.1+');
        }

        // Named arguments at a call site.
        if ($node instanceof Node\Arg && $node->name !== null) {
            $this->flag($node, 'named-arg', 'named arguments are PHP 8.0+');
        }

        // Declared types that postdate 7.4.
        foreach ($this->typeNodes($node) as $where => $type) {
            $name = $this->typeName($type);
            if ($name === null) {
                continue;
            }
            $lower = strtolower($name);
            if ($lower === 'static' && $where !== 'return') {
                continue; // `static` as a param type is a separate error the parser catches
            }
            if (isset(PHP8_TYPES[$lower])) {
                $this->flag($node, 'type-' . $lower, "`$lower` type is PHP " . PHP8_TYPES[$lower] . '+');
            }
        }

        // PHP 8 standard-library functions.
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $fn = strtolower($node->name->toString());
            if (isset(PHP8_FUNCTIONS[$fn])) {
                $this->flag($node, 'php8-function', "$fn() is PHP " . PHP8_FUNCTIONS[$fn] . '+');
            }
        }

        // `throw` used as an EXPRESSION (7.4 only allows the statement form).
        // php-parser models every throw as Expr\Throw_, so a plain
        // `throw new X();` is Stmt\Expression wrapping one — that is the legal
        // 7.4 form. Mark it on the way past the parent, and only flag the ones
        // that were never marked (i.e. `$x = $y ?? throw new X()`).
        if ($node instanceof Node\Stmt\Expression && $node->expr instanceof Node\Expr\Throw_) {
            $node->expr->setAttribute('arvrs_stmt_throw', true);
        }
        if ($node instanceof Node\Expr\Throw_ && !$node->getAttribute('arvrs_stmt_throw', false)) {
            $this->flag($node, 'throw-expression', 'throw as an expression is PHP 8.0+');
        }

        // Trailing comma in a parameter list is 8.0 (in a *call* it is 7.3, which is fine).
        if (($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_
             || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction)
            && $node->getAttribute('comments') === null) {
            // handled by the parser; nothing to do here
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function typeNodes(Node $node): array
    {
        $out = [];
        if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            if ($node->returnType !== null) {
                $out['return'] = $node->returnType;
            }
        }
        if ($node instanceof Node\Param && $node->type !== null) {
            $out['param'] = $node->type;
        }
        if ($node instanceof Node\Stmt\Property && $node->type !== null) {
            $out['property'] = $node->type;
        }
        return $out;
    }

    /** @param mixed $type */
    private function typeName($type): ?string
    {
        if ($type instanceof Node\NullableType) {
            $type = $type->type;
        }
        if ($type instanceof Node\Identifier || $type instanceof Node\Name) {
            return $type->toString();
        }
        return null;
    }
}

// ---------------------------------------------------------------- collection

$roots = array_slice($argv, 1);
if (!$roots) {
    // The runtime surface only. `vendor/` is dev-only and `bin/`/`tests/` never
    // ship, but they still run in CI, so they are checked too.
    $roots = ['arvan-reseller.php', 'uninstall.php', 'src', 'templates', 'tests', 'bin'];
}

$base  = dirname(__DIR__);
$files = [];
foreach ($roots as $root) {
    $path = $root[0] === '/' || preg_match('#^[A-Za-z]:#', $root) ? $root : $base . '/' . $root;
    if (is_file($path)) {
        $files[] = $path;
        continue;
    }
    if (!is_dir($path)) {
        fwrite(STDERR, "skip (not found): $root\n");
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'php' && strpos($f->getPathname(), 'vendor') === false) {
            $files[] = $f->getPathname();
        }
    }
}
sort($files);

// ------------------------------------------------------------------- parsing

$parser    = (new ParserFactory())->createForVersion(PhpVersion::fromString('7.4'));
$failures  = 0;
$checked   = 0;

foreach ($files as $file) {
    $code = file_get_contents($file);
    if ($code === false) {
        fwrite(STDERR, "unreadable: $file\n");
        $failures++;
        continue;
    }
    $rel = str_replace('\\', '/', substr($file, strlen($base) + 1));
    $checked++;

    try {
        $ast = $parser->parse($code);
    } catch (PhpParser\Error $e) {
        // A syntax error under the 7.4 grammar IS the finding: this file cannot
        // run on the minimum supported runtime at all.
        printf("FAIL %s:%d  syntax: %s\n", $rel, $e->getStartLine(), $e->getRawMessage());
        $failures++;
        continue;
    }
    if ($ast === null) {
        continue;
    }

    $visitor   = new Php74Visitor();
    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    foreach ($visitor->violations as $v) {
        printf("FAIL %s:%d  %s: %s\n", $rel, $v['line'], $v['rule'], $v['detail']);
        $failures++;
    }
}

printf("\n%d file(s) parsed against the PHP 7.4 grammar; %d violation(s).\n", $checked, $failures);
exit($failures > 0 ? 1 : 0);
