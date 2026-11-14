# Onenew official admin customization

Keeps the official XB admin layout, routing, authentication, node editor, TLS and multiplex controls. A deterministic source-level patch inserts one readable React component into the original advanced editor, removes four sidebar items, and inserts proxy health after the node ID. There is no DOM patching or independent admin replacement.

## Reproducible build

Requires Node 22+ and pnpm 11.25.0. Obtain the official admin distribution from `cedar2025/xboard-admin-dist` at **ef5f43da335092cbff8fdf0ad7ff9b4d92d7d0d7**. From this directory:

```sh
pnpm install --frozen-lockfile
pnpm build --upstream-dir /absolute/path/to/official-admin
ONENEW_ADMIN_UPSTREAM=/absolute/path/to/official-admin pnpm test
```

The only development dependency is exactly pinned `esbuild@0.28.2`. If the runtime already supplies that version, `ONENEW_ESBUILD_MODULE` may specify its absolute `lib/main.js` path and `node build.mjs --upstream-dir ...` builds without installing dependencies. The default upstream path is the sibling `outputs/xboard-admin-dist` in this workspace.

`dist/` is a full copy of that upstream distribution, with only `assets/index-CEIYH7i8.js` and `assets/index-DiYa-_z_.css` changed, plus `onenew-build.json` containing input/output hashes. Deploy the contents to the existing official admin asset location. Preserve all upstream license notices. Generated distributions and node_modules are ignored and must not be committed. `upstream.json` checks the exact SHA256 of the HTML, JS, CSS and advanced editor function; unsupported versions fail before writing output. The complete generated module is parsed by esbuild before it is written.

## Behavior

- The advanced dialog presents TLS / Multiplex when supported by the original form, and one Chinese “落地链接解析” tab. Select the actual kernel, enter a link, choose services or custom domain suffixes, generate an explicit JSON preview, then confirm the preview into the dialog draft. The original Save commits that draft; Cancel discards it. The parent node editor still requires its normal Save.
- Default mode removes **every old custom route and outbound**. Preserve mode replaces selected exact service domains (plus Binance owned subdomains), prepends the replacement rules, retains remaining pure-domain routes in their original order, and removes only newly unused replaced outbounds. Complex, global, mixed-kernel and unknown rules fail closed. Shared tag conflicts require a new tag. Preserve mode rejects global routing; use full replacement.
- The service/domain catalog is a read-only JSON extraction from the user's `python3.10.sh` `_xb_auto_v4_catalog_json` block. The attachment is never executed. AI includes the full catalog; Gemini is separately selectable; Google Services is separately selectable.
- Supported link families: SOCKS/SOCKS5, Shadowsocks AEAD/2022 without plugins, VLESS, VMess JSON, Trojan, Hysteria2 and TUIC. Sing-box emits the common XB `protocol/tag/settings` envelope with native settings and `domain_suffix` routes. TCP, WebSocket and gRPC are accepted where representable. All unknown query parameters, unsupported transports and malformed custom domains are rejected rather than silently omitted.
- Standard XB-node **Xray only supports plain TCP SOCKS, SS, VLESS and VMess through this field**. Its compiler does not promote stream settings. TLS, REALITY, WS, gRPC, flow and VLESS ENC are explicitly rejected. The separate customized caddy support for stream settings is not assumed. VLESS ENC is also rejected for Sing-box.
- For the pinned customized caddy build, explicitly select **Xray (caddy)**. This mode promotes `stream_settings` for TLS, REALITY, VLESS ENC/Vision, WS, gRPC and XHTTP. Do not select it for an unmodified XB-node. Reserved built-in tags are rejected for every kernel.
- Links and credentials stay in component state. No parser network request, localStorage, telemetry or credential logging is introduced. JSON previews intentionally display credentials for administrator review. Raw arbitrary JSON importing/editing is outside this first milestone.
- Node status uses `node.proxy_health`, never heartbeat: successful result under ten minutes → `连通在线`, failed result under ten minutes → `故障`, all other/stale/future/malformed results → `待检测`. `finished_at` is expected as an ISO timestamp. Cells refresh age every 30 seconds; result fetching follows the original table refetch lifecycle.

## Verification and boundaries

`node --test *.test.mjs` covers real parser outputs and rejection paths, safe merge behavior, SOCKS standard/URL-safe padded/raw Base64 (including `/`), IPv6, native kernel routing, REALITY fields, ENC refusal, state freshness, tampered upstream refusal, the actual patched React element tree and draft Save/Cancel callbacks, menu hrefs and status-column data flow. Bundle compilation and full-module parse are part of every build. Browser visual QA and full authenticated integration are run by the root task; these unit checks do not claim a live proxy connection or node deployment.
