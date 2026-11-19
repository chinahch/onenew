# Client TLS fragmentation

The VMess, VLESS and Trojan TLS editors expose an optional Shadowrocket-specific
fragment switch. It is disabled by default. Settings are persisted in
`protocol_settings.tls_settings.fragment` with `enabled`, `length`, and `interval`.

The default length is `100-200` bytes and the default interval is `10-20` ms.
Length accepts one integer or an ascending range within 1–16384. Interval accepts
one integer or an ascending range within 0–1000. These are client preferences,
not server TLS settings; the fragment object is removed from native node config.

Enabled TCP TLS/REALITY nodes in the dedicated Shadowrocket subscription receive
the top-level query parameter `reserved=1,<length>,<interval>`. A disabled flag,
plaintext connection, invalid range, or UDP/QUIC transport does not emit it.
General links and Clash/Mihomo subscriptions remain unchanged.

This is a client-specific export dialect, not a universal share-link standard.
The mapping follows a first-hand, manually exported Shadowrocket configuration
recorded in ThunderRonin/xhttp-sub-worker's `SHADOWROCKET-XHTTP.md`:

```text
https://github.com/ThunderRonin/xhttp-sub-worker/blob/master/SHADOWROCKET-XHTTP.md
```

Validate on the actual client after updating the dedicated Shadowrocket
subscription: confirm TLS → Fragment shows enabled, inspect the imported length
and interval, and test the connection. Automated tests verify editor persistence,
range validation, serialization and exporter isolation; they do not run the
closed-source Shadowrocket engine or guarantee connectivity on a filtered path.

Do not add this reserved parameter to other subscription formats without their
own documented support and validation. No client binary, server binary or
database schema migration is required for this panel setting.
