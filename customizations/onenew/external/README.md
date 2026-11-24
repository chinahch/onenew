# External nodes in Shadowrocket subscriptions

The Shadowrocket (小火箭) subscription must keep the normal base64-wrapped Shadowrocket payload. External nodes are appended as their original share URIs inside that payload, in the same order as managed nodes. They are not converted to server-side credentials or treated as native nodes.

The external-node customization patches both the General and Shadowrocket URI builders, and bypasses conversion-only validation for these two URI-preserving clients. Mihomo, legacy Clash, and sing-box keep their existing native-conversion and validation paths.

Regression coverage includes a mixed Shadowrocket subscription containing an external IPv6 VLESS XHTTP/H3 link and a managed VLESS node. The response must remain a valid base64 text subscription and retain both entries. Run `vendor/bin/phpunit --bootstrap vendor/autoload.php customizations/onenew/external/tests/ExternalNodesTest.php` with the Onenew provider installed and a disposable test database.
