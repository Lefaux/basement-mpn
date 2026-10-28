<?php

declare(strict_types=1);

namespace Deployer;

require 'recipe/common.php';
require 'recipe/symfony.php';

set('application', 'basement-mpn');
set('keep_releases', 5);
set('allow_anonymous_stats', false);
set('ssh_options', ['IdentitiesOnly' => 'yes']);

$deployHost = getenv('DEPLOY_HOST') ?: throw new \RuntimeException('DEPLOY_HOST must be set.');
$deployRemoteUser = getenv('DEPLOY_REMOTE_USER') ?: throw new \RuntimeException('DEPLOY_REMOTE_USER must be set.');
$deployPath = getenv('DEPLOY_PATH') ?: throw new \RuntimeException('DEPLOY_PATH must be set.');

// Dependencies and compiled assets are built in CI and uploaded with rsync.
set('rsync_src', __DIR__ . '/');
set('rsync_excludes', [
    '.git',
    '.github',
    '.env.local',
    'basement-track-scanner-e00b7b328e23.json',
    'node_modules',
    'tests',
    'var/cache',
    'var/log',
    'var/tmp',
]);
set('rsync_flags', '-az --delete --links --times --chmod=D755,F644');

set('shared_files', ['.env.local']);
set('shared_dirs', ['var/log']);
set('writable_mode', 'chmod');
set('writable_dirs', ['var/cache', 'var/log']);

host($deployHost)
    ->set('remote_user', $deployRemoteUser)
    ->set('deploy_path', $deployPath);

task('deploy:vendors', static function (): void {
    // Dependencies are built in CI and uploaded with the artifact.
});

task('deploy:check_env_local', static function (): void {
    $sharedEnvLocal = '{{deploy_path}}/shared/.env.local';
    if (!test('[ -f ' . $sharedEnvLocal . ' ]')) {
        throw new \RuntimeException(sprintf('Missing shared production env file at %s. Create it before deploying.', $sharedEnvLocal));
    }
});

task('deploy:update_code', static function (): void {
    $src = rtrim(get('rsync_src'), '/') . '/';
    $dest = parse('{{remote_user}}@{{hostname}}:{{release_path}}');
    $excludes = '';

    foreach (get('rsync_excludes') as $exclude) {
        $excludes .= ' --exclude=' . escapeshellarg($exclude);
    }

    runLocally(sprintf('rsync %s%s %s %s', get('rsync_flags'), $excludes, $src, $dest));
});

task('db:backup', static function (): void {
    $timestamp = date('Ymd_His');
    $backupDir = '{{deploy_path}}/shared/db_backups';
    $backupFile = $backupDir . '/db_' . $timestamp . '.sql';

    run('mkdir -p ' . $backupDir);
    $dumpCheck = trim(run('if command -v mysqldump >/dev/null 2>&1; then echo mysqldump; elif command -v mariadb-dump >/dev/null 2>&1; then echo mariadb-dump; else echo none; fi'));
    if ($dumpCheck === 'none') {
        writeln('<comment>Database backup skipped: neither mysqldump nor mariadb-dump is available on the host.</comment>');

        return;
    }

    run('cd {{release_path}} && {{bin/php}} bin/deploy_db_backup.php ' . escapeshellarg($backupFile));
    run('gzip -f ' . $backupFile);
});

task('deploy:migrate', static function (): void {
    run('cd {{release_path}} && {{bin/console}} doctrine:migrations:migrate --no-interaction --allow-no-migration --env=prod');
});

after('deploy:failed', 'deploy:unlock');
before('deploy:migrate', 'db:backup');

task('deploy', [
    'deploy:prepare',
    'deploy:release',
    'deploy:update_code',
    'deploy:check_env_local',
    'deploy:shared',
    'deploy:writable',
    'deploy:migrate',
    'deploy:cache:clear',
    'deploy:cache:warmup',
    'deploy:symlink',
]);

set('bin/php', getenv('DEPLOY_PHP_BIN') ?: '/usr/local/bin/php');
set('bin/console', '{{bin/php}} {{release_path}}/bin/console');

set('opcache_fcgi', static function (): ?string {
    $env = getenv('OPCACHE_FCGI');

    return $env !== false && $env !== '' ? $env : null;
});

task('opcache:flush', static function (): void {
    $candidates = [];
    $configuredFcgi = get('opcache_fcgi');
    if (is_string($configuredFcgi) && $configuredFcgi !== '') {
        $candidates[] = $configuredFcgi;
    }

    $detectedSockets = trim(run('find /tmp -maxdepth 1 -type s -name ".fpm_*.sock" 2>/dev/null | sort'));
    if ($detectedSockets !== '') {
        foreach (preg_split('/\R+/', $detectedSockets) ?: [] as $socket) {
            if (($socket = trim($socket)) !== '') {
                $candidates[] = $socket;
            }
        }
    }

    $candidates = array_values(array_unique($candidates));
    if ($candidates === []) {
        throw new \RuntimeException('OPcache reset failed: no configured or detected FastCGI endpoint available.');
    }

    run('if [ ! -x {{deploy_path}}/bin/cachetool.phar ]; then mkdir -p {{deploy_path}}/bin && curl -sL https://github.com/gordalina/cachetool/releases/latest/download/cachetool.phar > {{deploy_path}}/bin/cachetool.phar && chmod +x {{deploy_path}}/bin/cachetool.phar; fi');

    $errors = [];
    foreach ($candidates as $fcgi) {
        try {
            writeln(sprintf('<comment>Trying OPcache reset via %s</comment>', $fcgi));
            run('{{deploy_path}}/bin/cachetool.phar opcache:reset --fcgi=' . escapeshellarg($fcgi));

            return;
        } catch (\Throwable $exception) {
            $errors[] = sprintf('%s: %s', $fcgi, $exception->getMessage());
        }
    }

    throw new \RuntimeException("OPcache reset failed for all FastCGI endpoints:\n" . implode("\n", $errors));
});

after('deploy:symlink', 'opcache:flush');
