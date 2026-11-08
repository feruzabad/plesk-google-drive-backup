<?php

/**
 * Keep only the generated Google Drive service classes from google/apiclient-services.
 *
 * The full package contains every Google API and is roughly 150 MB unpacked.
 * The extension only needs Drive.php and Drive/*.php (about 0.5 MB).
 */

$vendorDir = isset($argv[1]) ? rtrim($argv[1], '/') : null;
if (!$vendorDir || !is_dir($vendorDir)) {
    fwrite(STDERR, "Usage: php prune-google-services.php <vendor-dir>\n");
    exit(2);
}

$servicesDir = $vendorDir . '/google/apiclient-services';
$sourceDir = $servicesDir . '/src';
if (!is_dir($sourceDir)) {
    fwrite(STDERR, "google/apiclient-services is not installed at {$servicesDir}\n");
    exit(1);
}

$keepFile = $sourceDir . '/Drive.php';
$keepDir = $sourceDir . '/Drive';
if (!is_file($keepFile) || !is_dir($keepDir)) {
    fwrite(STDERR, "Drive service classes were not found; refusing to prune.\n");
    exit(1);
}

$removed = 0;
$removedBytes = 0;
$items = scandir($sourceDir);
foreach ($items as $item) {
    if ($item === '.' || $item === '..' || $item === 'Drive.php' || $item === 'Drive') {
        continue;
    }
    $path = $sourceDir . '/' . $item;
    $size = is_dir($path) ? directorySize($path) : filesize($path);
    if (is_dir($path)) {
        removeDirectory($path);
    } else {
        unlink($path);
    }
    $removed++;
    $removedBytes += $size;
}

// These are not needed at runtime.
foreach (['tests', 'generator', '.github'] as $extra) {
    $path = $servicesDir . '/' . $extra;
    if (is_dir($path)) {
        $removedBytes += directorySize($path);
        removeDirectory($path);
        $removed++;
    }
}

$keptBytes = directorySize($keepDir) + filesize($keepFile);
printf(
    "Pruned %d Google API entries (%.1f MB removed); kept Drive service (%.1f MB).\n",
    $removed,
    $removedBytes / 1048576,
    $keptBytes / 1048576
);

function directorySize($dir)
{
    $size = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    return $size;
}

function removeDirectory($dir)
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($dir);
}
