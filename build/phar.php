<?php
// build/phar.php

$pharFile = __DIR__ . '/../dist/changeset.phar';

// Clean up old files
if (file_exists($pharFile)) {
    unlink($pharFile);
}
if (file_exists("$pharFile.gz")) {
    unlink("$pharFile.gz");
}

// Create a new Phar
$phar = new Phar($pharFile);

// Start buffering. Mandatory to modify stub.
$phar->startBuffering();

// Create the default stub from a simple CLI-only stub
$defaultStub = $phar->createDefaultStub('bin/console');

// Add the project files to the phar
$phar->buildFromDirectory(__DIR__ . '/../', '/vendor|bin|src|config|public|composer\.(json|lock)/');

// Customize the stub to add the shebang and make it executable
$stub = "#!/usr/bin/env php\n" . $defaultStub;

// Set the stub
$phar->setStub($stub);

// Stop buffering and save the phar file
$phar->stopBuffering();

// Optionally compress the phar file with Gzip
$phar->compressFiles(Phar::GZ);

echo "$pharFile successfully created" . PHP_EOL;
