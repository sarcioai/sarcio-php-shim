# sarcio/shim (PHP)

Connects a PHP application to [Sarcio](https://www.sarcio.io), so an approved
server-side fix takes effect on a running app without a deploy. It ships a
Laravel middleware and a Symfony kernel listener, has no third-party runtime
dependencies, and works with the Sarcio sidecar running on the same host.

Setup guides, the full settings reference and troubleshooting are in the
[Sarcio docs](https://www.sarcio.io/docs).

## Requirements

- PHP 8.1 or newer with `ext-json`
- The Sarcio sidecar on the same host (see the [docs](https://www.sarcio.io/docs))
- Recommended under PHP-FPM: `ext-apcu`, so workers share one cached lookup

## Install (Laravel)

```bash
composer require sarcio/shim:^0.2
```

```dotenv
SARCIO_SIDECAR_DSN=unix:///run/sarcio/sarcio.sock
SARCIO_SITE_KEY=pk_your_site
SARCIO_EVALUATE_TIMEOUT_MS=25
SARCIO_ROUTESET_TTL_SEC=5
```

Register `Sarcio\Shim\Laravel\SarcioServiceProvider` (auto-discovered) and add
`Sarcio\Shim\Laravel\SarcioMiddleware` to the global HTTP stack.

For Symfony, register `Sarcio\Shim\Symfony\SarcioKernelListener` on
`kernel.request` and `kernel.response`.

## Using a fix in your own code

Some fixes relax a validation rule or change a setting rather than the response.
Read them from the request:

```php
$ctx = $request->attributes->get('sarcio'); // ['skipValidations' => [...], 'config' => [...]]
if (!in_array('referralCodeRequired', $ctx['skipValidations'] ?? [], true)) {
    // enforce the rule as normal
}
```

## Safe by default

- A request to a route with no active fix does no extra work beyond a local
  lookup.
- If the sidecar is unreachable, slow or returns anything unexpected, the
  request runs exactly as it would without Sarcio.
- `Authorization`, `Cookie` and other credential headers never leave your
  application, and request bodies are size-capped.

## File-level fixes

A fix that replaces a PHP source file is applied by a small watcher rather than
by the middleware. Run it under your process supervisor, or from cron:

```bash
sarcio-apply-files \
  --dsn=unix:///run/sarcio/sarcio.sock --site=pk_your_site \
  --app-root=/var/www/app --backup-dir=/var/lib/sarcio/backups --interval=5
```

It checks each file's integrity before writing, keeps a backup of the original,
and restores it when the fix is retired or expires. If anything does not check
out it changes nothing. Paths outside `--app-root` are refused.

Driven from cron (a fresh PHP process per pass), give it a state file so a later
pass knows which originals to restore:

```php
new FileSwapApplier($dsn, $siteKey, $appRoot, $backupDir, 1.0, $token,
    new Sarcio\Shim\State\JsonFileSwapState('/var/lib/sarcio/state.json'));
```

## Support

[www.sarcio.io/docs](https://www.sarcio.io/docs), email
[support@sarcio.io](mailto:support@sarcio.io), or open an issue on this
repository.

## License

[Business Source License 1.1](LICENSE) (source-available): production use to
connect your own applications to a Sarcio subscription or trial is granted;
offering a competing service is not. Each version converts to Apache-2.0 four
years after its release.

The same core code is also made available under GPL-2.0-or-later as part of the
[Sarcio WordPress plugin](https://www.sarcio.io/docs), which
bundles it. That grant covers the copy distributed inside the plugin; this
Composer package is licensed under BUSL-1.1.
