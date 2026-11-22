<?php
/**
 * Minimal test harness (no Composer / PHPUnit needed).
 *
 * The application files are procedural and run side effects on include (DB, session,
 * redirects), so they cannot simply be require'd in a test. loadFunctions() therefore
 * extracts ONLY the named top-level functions from a source file with PHP's tokenizer
 * and defines them. Production code is not modified or duplicated.
 */

$GLOBALS['__tests']    = [];
$GLOBALS['__failures'] = [];

function loadFunctions(string $file, array $names): void
{
    $src = file_get_contents($file);
    if ($src === false) {
        throw new RuntimeException("Cannot read {$file}");
    }
    $tokens = token_get_all($src);
    $n      = count($tokens);
    $found  = [];

    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        // name of the function (skip whitespace; closures have no name)
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if ($j >= $n || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
            continue;
        }
        $name = $tokens[$j][1];
        if (!in_array($name, $names, true)) {
            continue;
        }

        // collect from `function` to the matching closing brace of the body
        $code  = '';
        $depth = 0;
        $begun = false;
        for ($k = $i; $k < $n; $k++) {
            $t    = $tokens[$k];
            $text = is_array($t) ? $t[1] : $t;
            $code .= $text;

            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                $begun = true;
            } elseif ($t === '}') {
                $depth--;
                if ($begun && $depth === 0) {
                    break;
                }
            }
        }
        eval($code);
        $found[] = $name;
    }

    $missing = array_diff($names, $found);
    if ($missing) {
        throw new RuntimeException("Function(s) not found in {$file}: " . implode(', ', $missing));
    }
}

function t(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$name, $fn];
}

function eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($msg !== '' ? $msg . "\n" : '')
            . '  expected: ' . var_export($expected, true) . "\n"
            . '  actual:   ' . var_export($actual, true)
        );
    }
}

function runTests(): int
{
    $pass = 0;
    foreach ($GLOBALS['__tests'] as [$name, $fn]) {
        try {
            $fn();
            echo "  ok    {$name}\n";
            $pass++;
        } catch (Throwable $e) {
            echo "  FAIL  {$name}\n" . preg_replace('/^/m', '        ', $e->getMessage()) . "\n";
            $GLOBALS['__failures'][] = $name;
        }
    }
    $fail = count($GLOBALS['__failures']);
    echo "\n{$pass} passed, {$fail} failed\n";
    return $fail === 0 ? 0 : 1;
}
