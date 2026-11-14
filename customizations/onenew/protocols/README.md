# XHTTP JSON objects and H3 templates

This overlay targets the pinned panel source (`e87e69a`). It does not replace the node protocol or globally apply `JSON_FORCE_OBJECT`.

Root installer integration:

```php
copyOverlay($custom . '/protocols/app', $root . '/app');
require $custom . '/protocols/install.php';
applyOnenewProtocolPatch($root);
```

The patch preflights all source anchors before writing any file. It changes:

- `Server` protocol getter/setter: retain explicit raw XHTTP `{}` shapes, and restore known XHTTP map fields after HTTP associative decoding or legacy `[]` storage.
- `ServerService::buildNodeConfig`: normalize XHTTP at the shared node payload boundary used by HTTP and WebSocket sync.
- `General`: encode extra objects for VMess/VLESS/Trojan subscriptions (including V2rayN/V2rayNG), and retain `network_settings.alpn` as sharing-link ALPN.

Only known map fields are repaired when the original object/list distinction has already been lost: `extra`, `extra.headers`, `extra.xmux`, `extra.downloadSettings`, its `tlsSettings`, `realitySettings`, `sockopt`, `xhttpSettings`, and nested XHTTP extras. Unrelated fields and actual lists (hosts, paths, user-defined lists, etc.) remain arrays. A raw JSON string still carries shape information, so unknown empty objects there remain objects.

The real pinned save path is `V2/Admin/Server/ManageController -> Server` mutator; node delivery is `ServerService::buildNodeConfig`. V2node is a middleware protocol alias, rather than a separate protocol-settings persistence implementation in this checkout. Additional custom V3 node routes should call the same builder. This does not add XHTTP capabilities to client formats that do not implement them; in particular, it does not promise parity with Mihomo/Sing-box/Shadowrocket format-specific features.

H3 is a selectable JSON template inside the original compiled editor. It is still editable. It needs a supporting Xray/client version, regular TLS with ALPN `h3`, and working UDP/QUIC reachability. REALITY is not an arbitrary interchangeable H3 security option. The node integration must promote the template's ALPN to Xray `tlsSettings.alpn`; merely keeping `alpn` inside `xhttpSettings` is insufficient.

## Validation

```powershell
../../work/php-runtime/php.exe -n customizations/onenew/protocols/regression.php
../../work/php-runtime/php.exe -n customizations/onenew/protocols/patch.test.php
node --test customizations/onenew/admin/*.test.mjs
```

`regression.php` loads the real pinned model mutator, node builder and General sharing builders with a minimal attribute-storage adapter. It verifies serialized node/share JSON and list preservation. `patch.test.php` checks upstream drift cannot cause partial writes. Both can run without Laravel dependencies. Copy `tests/ProtocolSettingsTest.php` into the existing disposable Laravel CI suite to additionally verify actual Eloquent/database reload and `toArray()` behavior.

The regression was first observed failing with `vmess save: headers must be object {}` before implementing this overlay. The editor regression first failed because only the XHTTP template existed. Full Laravel tests require the project's normal container dependencies and are separate from these standalone checks.
