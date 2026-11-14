# Machine egress preference

The setting applies to all logical caddy nodes assigned to a panel machine.
It does not change host networking, subscriptions, or stored custom outbounds.

Modes: `inherit` preserves local YAML for the machine (and follows the machine
setting for a node); `default` clears local YAML family restrictions;
`prefer_ipv4` and `prefer_ipv6` keep the opposite family available as fallback.
A missing settings row is equivalent to machine `inherit`.

GET `/api/onenew/admin/machine-egress?machine_id=1` and POST the same endpoint
require admin authentication. POST accepts `machine_id`, `machine_identity`, `node_identities`, `mode`, and
`node_overrides`, a complete ID-to-mode map. Nodes absent from that map inherit.
The response is `{machine_id, mode, nodes: [{id, name, type, mode, effective_mode}]}`.
Unknown or foreign node IDs are rejected atomically. Persisted overrides carry
the node's `onenew_identity` so reused integer IDs cannot inherit stale policy.
Machine deletion removes its settings through a cascading foreign key.

## Integration

1. Copy `egress/app` into Laravel app, load `egress/database/migrations`, and
   require `egress/routes.php` from the Onenew service provider.
2. Near the end of `ServerService::buildNodeConfig`, add:

   ```php
   $ipFamily = \App\Onenew\MachineEgress::forNode($node);
   if ($ipFamily !== null) {
       $response['ip_family'] = $ipFamily;
   }
   ```

3. Run `node/egress-patch.py --source PREPARED_SOURCE` after `node/hot_apply.py`.
   The source must match the pinned upstream commit. The patch adds feature
   `onenew-panel-egress-v1`; native release CI must apply and require it.
4. Include `egress/tests/MachineEgressTest.php` in the disposable Laravel test
   image. `php egress/tests/policy.php` runs the independent policy tests.

Saving pushes `sync.config` for all nodes after transaction commit. Offline
nodes receive the effective policy on their next config fetch. Native model
hashes include the field; Xray replaces the logical node instance, while
sing-box applies routes and closes that logical node's tracked TCP/UDP sessions.
Sibling logical nodes retain private configs and sessions.

The patch and backend require a new tested native release before deployment.
Existing installed binaries ignore `ip_family`; this source change deliberately
does not change the published version or caddy service names.

Requests include identities from the opened editor snapshot. Replaced machines/nodes or changed node lists cause HTTP 409; refresh before saving.
