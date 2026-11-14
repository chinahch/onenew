# Native domain metadata collector

This overlay extends the pinned public caddy source, retaining its actual user
authentication and per-service node identity. Both Xray's admitted dispatcher and
sing-box's admitted connection tracker report destination hostnames. A shared
machine worker uses the existing machine identity over HTTPS; tokens never enter
URLs or metadata. Local HTTP is accepted only for loopback tests.

The administrator enables collection in the **访问域名** date-detail tab. The worker
polls authenticated configuration every ten seconds. Default: off, seven-day
retention. The panel permits retention from one to thirty days. Disabling stops
new collection and discards pending in-memory events.

Only `user_id`, `node_id`, DNS hostname and connection time are reported. No full
URLs, paths, queries, page contents, source IPs or passwords are captured. IP-only
destinations and invalid hostnames are omitted. Counts are reported connection
metadata, not a count of pages viewed or proof that content loaded. Encrypted
hostname techniques and protocols may leave domains unavailable; this feature
does not reconstruct or decrypt them.

Queues and each drain are bounded to 512 events. A slow/unavailable panel can
cause dropped metadata; this is not an exhaustive browsing history. Batches have
stable UUIDs across transient retries. Permanent node ownership/configuration
errors drop that batch and allow other nodes to continue. Process exit discards
the memory queue. Historical domains before enabling cannot be recovered.

`.github/workflows/onenew-node-ci.yml` prepares upstream commit
`0a29338e1f102a462363ce3527417029f89bab28` through public caddy customization
`11f025992aa163204c5d4d2d8fb0934a0ab2e893`, verifies both kernels and builds a separate
amd64/arm64 Onenew packages. Successful master builds publish a versioned preview
release; see [native installation](../node/README.md). It does not change an existing server. Existing caddy packages lack this new collector;
their heartbeat or traffic reports cannot supply domain history. Validate the
candidate on a separate node, then upgrade nodes through the normal binary
deployment process before expecting domains in the panel.

Local unit command: `go test ./...` in this directory. The patcher refuses changed
source anchors; run it only on a newly prepared pinned source directory.
