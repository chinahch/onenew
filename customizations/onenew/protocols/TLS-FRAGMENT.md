# Client TLS fragmentation

The VMess, VLESS and Trojan TLS editors expose an optional Shadowrocket-specific
fragment switch. It is disabled by default. Settings are persisted in
`protocol_settings.tls_settings.fragment` with `enabled`, `length`, and `interval`.

The default length is `100-200` bytes and the default interval is `10-20` ms.
Length accepts one integer or an ascending range within 1–16384. Interval accepts
one integer or an ascending range within 0–1000. These are client preferences,
not server TLS settings; the fragment object is removed from native node config.

Enabled TCP TLS/REALITY nodes in the dedicated Shadowrocket subscription receive
the top-level query parameter `fragment=1,<length>,<interval>,tlshello`. For example,
the default settings produce `fragment=1,100-200,10-20,tlshello` (commas are URL-encoded
in the generated link). A disabled flag,
plaintext connection, invalid range, or UDP/QUIC transport does not emit it.
General links and Clash/Mihomo subscriptions remain unchanged.

This is a client-specific export dialect, not a universal share-link standard.
The affected Shadowrocket client requires the `fragment` parameter and the trailing
`tlshello` mode to enable TLS → Fragment on import. The previous `reserved` tuple
did not enable that switch and is no longer generated. This correction follows
the reported on-device import behavior; do not substitute an unrelated reserved
parameter for the fragmentation setting.

Validate on the actual client after updating the dedicated Shadowrocket
subscription: confirm TLS → Fragment shows enabled, inspect the imported length
and interval, and test the connection. Automated tests verify editor persistence,
range validation, serialization and exporter isolation; they do not run the
closed-source Shadowrocket engine or guarantee connectivity on a filtered path.

Do not add this Shadowrocket-specific fragment parameter to other subscription
formats without their own documented support and validation. No server binary or
database schema migration is required for this panel setting.
