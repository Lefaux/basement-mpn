<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$outputFile = $argv[1] ?? '';
if ($outputFile === '') {
    fwrite(STDERR, "Missing output file path\n");
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

$dotenv = new Dotenv();
$dotenv->usePutenv()->load(__DIR__ . '/../.env', __DIR__ . '/../.env.local');
$url = getenv('DATABASE_URL') ?: '';
if ($url === '') {
    fwrite(STDERR, "DATABASE_URL not set\n");
    exit(1);
}

$parts = parse_url($url);
if ($parts === false) {
    fwrite(STDERR, "Failed to parse DATABASE_URL\n");
    exit(1);
}

parse_str($parts['query'] ?? '', $query);
$database = ltrim($parts['path'] ?? '', '/');
if ($database === '') {
    fwrite(STDERR, "Database name missing\n");
    exit(1);
}

$binary = trim((string) shell_exec('command -v mysqldump 2>/dev/null')) !== '' ? 'mysqldump' : 'mariadb-dump';
$command = [$binary, '--single-transaction', '--quick', '--lock-tables=false'];
foreach ([['-h', 'host'], ['-P', 'port'], ['-u', 'user']] as [$flag, $key]) {
    if (($parts[$key] ?? '') !== '') {
        $command[] = $flag;
        $command[] = $parts[$key];
    }
}
if (($query['unix_socket'] ?? '') !== '') {
    $command[] = '--socket=' . $query['unix_socket'];
}
$command[] = $database;

if (!is_dir(dirname($outputFile)) && !mkdir(dirname($outputFile), 0755, true)) {
    fwrite(STDERR, "Failed to create backup directory\n");
    exit(1);
}

$environment = $_ENV;
if (($parts['pass'] ?? '') !== '') {
    $environment['MYSQL_PWD'] = $parts['pass'];
}
$process = proc_open($command, [1 => ['file', $outputFile, 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
if (!is_resource($process)) {
    fwrite(STDERR, "Failed to start database dump\n");
    exit(1);
}
$error = stream_get_contents($pipes[2]);
fclose($pipes[2]);
if (proc_close($process) !== 0) {
    fwrite(STDERR, $error ?: "Database dump failed\n");
    exit(1);
}
