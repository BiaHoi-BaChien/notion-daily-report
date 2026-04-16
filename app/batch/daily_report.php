<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/app/src/Bootstrap.php';

exit(\App\Bootstrap::runCli($projectRoot, $argv));
