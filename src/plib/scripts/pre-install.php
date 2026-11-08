<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80400) {
    echo "Google Drive Remote Storage requires PHP 8.4 or newer. Current PHP version: " . PHP_VERSION . PHP_EOL;
    exit(1);
}
