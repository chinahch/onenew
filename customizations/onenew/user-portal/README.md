# onenew user portal

Preserves the Luma Vue user centre: original top navigation, dashboard, subscription, orders/renewals, invitations, usage and node-status components/styles. `LICENSE.luma` retains the MIT notice. No Luma management pages or Go backend are included.

Build with Node 22.18+ (or 24+) and pnpm: `pnpm install --frozen-lockfile`, `pnpm test`, `pnpm build`. Vite emits `dist/` using `/onenew-user/` asset paths. The root integration serves that index at `/` and `/onenew-user/`. All APIs are same-origin; no client-secret or API-host env is used.

Locally the existing Luma web node_modules may be used through a junction. Invoke `node_modules/.bin/vue-tsc --noEmit` and `node_modules/.bin/vite build --configLoader native` directly: pnpm's install check refuses external junctions. Docker installs normally from the committed lockfile.

The root provider copies backend/app/Onenew into Laravel app/Onenew, loads backend/routes.php, and loads backend/database/migrations. Run migrations before using subscription/renewals. The new tables store only durable request-to-native-order references and encrypted native subscription URL selections; XB still owns accounts, balances, orders and subscription state.

Authentication uses native /api/v1/passport/auth/login and stores only data.auth_data in memory/sessionStorage. API requests omit cookies and use Authorization headers. Logout deletes only the current Sanctum token. Account changes invalidate in-flight responses. Admin profiles link to XB's configured secure path; the bundle has no Luma admin pages.

Renewals use native calendar period names, plan GB converted to bytes, and plan prices converted from currency units to cents. Native order amounts are already cents. Creation invokes OrderService::createFromRequest, which can reserve balance but does not call paid/open/checkout. Payment or native admin confirmation is required. Duplicate request IDs map transactionally to the same order, including replay after completion/cancellation.

Subscription URLs come from native getSubscribe. Since native Helper randomly chooses configured domains/patterns, one native URL is encrypted and persisted per user/token hash for stable refreshes. Explicit reset invokes native resetSecurity and warns about the connection UUID change. Invite-only registration locks a real native invitation and invokes RegisterService; failure rolls back invite consumption. Native email verification is supported. If external captcha is enabled, the UI clearly explains that this portal does not support that registration challenge; native validation is not bypassed.

XB daily traffic already includes the multiplier. Only daily rows are summed, avoiding duplicate monthly aggregates, while rate groups remain distinct. No second multiplier is applied. From/to always refer to panel-timezone archive dates; the UI shows that fixed source timezone and does not offer conversion of daily aggregates. XB does not preserve the former Luma raw bytes, hourly windows or node breakdown, so those unsupported controls/claims are removed with a visible explanation. CSV uses authenticated API results.

GET /api/onenew/user/nodes belongs exclusively to the health customization. Fresh real proxy probes determine online/abnormal independently from host heartbeat.

Checks: `node --test --experimental-strip-types src/*.test.ts`; `php -n -l` on all backend/test PHP files. Integration/mapping checks: `vendor/bin/phpunit --bootstrap vendor/autoload.php customizations/onenew/user-portal/tests` with the root provider/backend installed and a disposable test DB. Tests create QA-only randomized accounts; do not use production databases.
