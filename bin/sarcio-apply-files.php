#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * sarcio-apply-files — the PHP opcache fast-path watcher.
 *
 * Runs alongside a PHP-FPM deployment, reconciling signed file-swap patches from
 * the local Sarcio sidecar onto disk (backup + write + opcache invalidation),
 * and restoring originals when a patch retires or expires. On shutdown it
 * restores every file it applied, so stopping the watcher returns the app to its
 * committed source.
 *
 *   sarcio-apply-files --dsn=unix:///run/sarcio/sarcio.sock --site=pk_... \
 *       --app-root=/var/www/app --backup-dir=/var/lib/sarcio/backups --interval=5
 *
 * The shim token (if the sidecar requires one) comes from --shim-token or the
 * SARCIO_SHIM_TOKEN environment variable.
 */

use Sarcio\Shim\FileSwapApplier;

$autoloads = [__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../tests/autoload.php'];
foreach ($autoloads as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

$opts = getopt('', ['dsn:', 'site:', 'app-root:', 'backup-dir:', 'interval::', 'shim-token::']);
foreach (['dsn', 'site', 'app-root', 'backup-dir'] as $required) {
    if (empty($opts[$required])) {
        fwrite(STDERR, "missing --$required\n");
        exit(2);
    }
}

$shimToken = (string) ($opts['shim-token'] ?? getenv('SARCIO_SHIM_TOKEN') ?: '');

$applier = new FileSwapApplier(
    (string) $opts['dsn'],
    (string) $opts['site'],
    (string) $opts['app-root'],
    (string) $opts['backup-dir'],
    1.0,
    $shimToken,
);

$stop = false;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $handler = static function () use (&$stop): void {
        $stop = true;
    };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
}

fwrite(STDERR, "sarcio-apply-files: watching (dsn={$opts['dsn']})\n");
$interval = (int) ($opts['interval'] ?? 5);
$applier->watch($interval, static fn (): bool => $stop);

fwrite(STDERR, "sarcio-apply-files: restoring applied files and exiting\n");
$applier->restoreAll();
exit(0);
