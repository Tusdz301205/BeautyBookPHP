<?php

$directories = in_array('--views', $argv, true) ? ['storage/framework/views'] : ['app', 'routes', 'database/migrations', 'database/seeders', 'tests'];
$count = 0;
foreach ($directories as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') continue;
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file->getPathname()).' 2>&1', $output, $status);
        if ($status !== 0) {
            fwrite(STDERR, implode(PHP_EOL, $output).PHP_EOL);
            exit($status);
        }
        $output = [];
        $count++;
    }
}
echo "PASS: $count PHP files\n";
