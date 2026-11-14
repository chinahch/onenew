# Bundled native plugins

`SubscriptionGuard/` is the audited adaptation of the user-supplied archive, retaining its original `XBoard Plugin` author metadata and a source archive digest. The ZIP was read and extracted with path, type and size checks; no attachment code was executed.

## Root integration contract

1. Include this source in a read-only image location such as `/opt/onenew/bundled-plugins/SubscriptionGuard`.
2. The native `plugins/` bind mount can hide image files. On startup seed **only** `plugins/SubscriptionGuard` when that exact target directory does not exist. Use a temporary sibling plus an atomic rename where available. Never overwrite or merge into a customized installed directory.
3. Once the application schema is ready, if code `subscription_guard` is absent from `v2_plugins`, call `app(App\Services\Plugin\PluginManager::class)->install('subscription_guard')`. This performs native migrations, publishes the local dashboard asset and creates a disabled record. **Do not call enable.** Preserve an existing record's configuration and enabled status.
4. The upstream `installDefaultPlugins()` auto-enables core plugins. Do not put this plugin into `plugins-core` unless the root installer explicitly honors a per-plugin disabled-default policy. User-plugin seeding plus explicit `install()` avoids this behavior.
5. Version 1.0.1 removes the old secret token column. Do not automatically replace an existing 1.0.0 user directory. An administrator upgrading an existing installation must review the adaptation, back up normally, replace that plugin through the controlled upgrade path, and run its migration. The seed operation must not force-enable or silently upgrade it.

The native admin already supports `admin_menus` and `admin_crud`. Config metadata declares read-only log/event schemas with `/subscription-guard/logs` and `/subscription-guard/abuse` endpoints relative to its existing secure admin API prefix. No admin bundle modification is needed for plugin menu rendering. Native config/enable/disable continue through built-in plugin APIs. A local standalone shell remains at `/{secure_path}/subscription-guard` for manual ban/unban and familiar log viewing; it contains no account data until authenticated API requests succeed.

## Security corrections

- Original default auto-ban and automatic Telegram were replaced with disabled installation + alert-only/notification-off defaults.
- Full subscription tokens were removed from new log rows and Laravel warning context; the upgrade drops the obsolete token column. Logs select explicit safe fields rather than `*`.
- Original CDN scripts, `innerHTML` interpolation and inline event handlers were removed. The local dashboard uses `textContent`, a restrictive CSP and no-store responses. All data/action routes require admin auth; a secret URL is not an authorization check.
- Threshold changed from `>= maximum` to strict `> maximum`; invalid configuration fails closed for policy activation and skips guard execution without account mutations.
- IPv6 and mapped IPv4 normalization, bounded IPv4/IPv6 CIDR masks, whitelist-before-/24 grouping, atomic cache claims and per-user locks replace unsafe count/rate logic.
- Input ranges, bounded pagination and confirmed manual actions were added. Privileged target accounts are always protected. Unban records a review watermark without deleting historical pulls.

## Tests

```sh
php customizations/onenew/plugins/tests/policy.php
node --test customizations/onenew/plugins/tests/dashboard.test.mjs
vendor/bin/phpunit customizations/onenew/plugins/tests/SubscriptionGuardFeatureTest.php
```

The standalone PHP policy test covers exact threshold boundaries, IPv4/IPv6 normalization, mapped addresses, CIDR edges and invalid masks, grouping order, safe defaults and UA credential redaction. The Node test executes the real dashboard script with hostile audit fields and rejects HTML sinks. The Laravel feature suite covers native installation disabled defaults, guest/user/staff rejection, administrator data access, no token export, invalid input rejection, alert/ban/reset behavior, per-user rate scopes, time windows, protected accounts, review on unban and shell security headers. It prevents stray HTTP calls and fakes only Telegram dispatch. Its setup maps the application's hard-coded Redis settings store to the array driver for isolated tests.

Local verification uses the supplied PHP runtime without Laravel vendor dependencies for policy/lint; the full feature suite requires the root container's Composer dependencies and test database. These checks do not claim production multi-IP detection or external notification delivery.
