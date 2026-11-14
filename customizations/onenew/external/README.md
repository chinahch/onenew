# External subscription nodes

Node management includes an **外部节点** manager. Paste up to 200 share links per import, select users and/or permission groups, then import. Assignment is OR; empty assignment grants nobody. Edit names, links, assignments and enabled state; move rows up/down to order external records. External nodes appear after managed nodes in authorized subscriptions and use the original external credentials. They are never installed or pushed to caddy, and their upstream traffic is not measured by this panel.

Original links are encrypted with the panel APP_KEY. Keep the APP_KEY with the database backup. List APIs hide credentials; authenticated administrators can retrieve a link for editing. Administrators alone can manage records; ordinary user quota/expiry rules still apply to subscription requests.

General URI output retains the original link (changing only its display name, including VMess `ps`). Mihomo and sing-box produce client-native objects from the original credentials, without substituting panel UUIDs. Supported share schemes: VLESS, VMess, SS/SS2022, Trojan, Hysteria2/hy2, AnyTLS, TUIC, SOCKS/SOCKS5 and HTTP/HTTPS. Unknown protocols and malformed links are rejected. Fields unsupported by the chosen structured format produce an explicit error rather than silently dropping parameters. Standard sing-box cannot represent VLESS native encryption or XHTTP; use General or compatible Mihomo for those links. Legacy Clash supports only its own older protocol subset.

References: [Mihomo VLESS](https://wiki.metacubex.one/config/proxies/vless/), [sing-box VLESS](https://sing-box.sagernet.org/configuration/outbound/vless/), [sing-box transports](https://sing-box.sagernet.org/configuration/shared/v2ray-transport/).

Import validates all lines before saving and is atomic. Ordering is transactional and rejects an outdated full-list order. No subscriptions or URLs are fetched by the parser.
