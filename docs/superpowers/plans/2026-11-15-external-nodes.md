# External subscription nodes implementation plan

User requirements: add multiple external share links from node management, assign individual users or permission groups, edit and sort, include authorized enabled nodes in subscriptions. User selected General URI plus Clash/Mihomo and sing-box conversion. External credentials remain those supplied in each link and never use the subscriber's panel UUID; no machine installation or configuration push.

Approach: keep external records separate from managed Server records, encrypt original links, and integrate at subscription generation only. Assignment is OR across explicit user IDs and permission-group IDs; empty assignments grant nobody. User expiry/quota checks remain the native subscription gate. Sorting is within external nodes, appended after managed nodes. Preserve original URI parameters for General; structured conversions validate unsupported fields rather than silently dropping them.

- [ ] Tests: URI parsing, exact independent SOCKS username/password, REALITY, SS2022, raw URI preservation, conversion refusal; administrative ACL/CRUD, user/group access, edit/order, native and external credential separation.
- [ ] Encrypted database storage, authorized administrative API, bounded batch import and search.
- [ ] Subscription projection, General raw output and explicit Clash/Mihomo/sing-box builders.
- [ ] Existing node toolbar external-node manager; batch import, checkboxes/search for users/groups, edit, enable/delete, move up/down ordering.
- [ ] Pinned bundle regression, full container CI, fresh review and publish.
