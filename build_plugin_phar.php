<?php
declare(strict_types=1);

// Helper script to build PMMP-QXRND.phar
$pluginDir = __DIR__ . '/plugins/PMMP-QXRND';
$pharPath = __DIR__ . '/plugins/PMMP-QXRND.phar';

if (!is_dir($pluginDir)) {
    echo "Directory not found: $pluginDir\n";
    exit(1);
}

if (file_exists($pharPath)) {
    unlink($pharPath);
}

$phar = new Phar($pharPath);
$phar->startBuffering();
$phar->buildFromDirectory($pluginDir);
$phar->stopBuffering();

echo "Built successfully: $pharPath\n";
