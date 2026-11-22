<?php
/** Run: php tests/run.php */
error_reporting(E_ALL);
require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}
exit(runTests());
