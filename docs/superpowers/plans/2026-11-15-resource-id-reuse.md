# Resource ID gap reuse implementation plan

User decision: reuse real node and machine primary keys, including installer machine IDs. Existing rows never renumber. Allocate smallest unused positive ID independently for each resource type.

Approaches: a reusable display number would preserve internal monotonic IDs, but the user explicitly rejected it. Resetting AUTO_INCREMENT does not fill interior gaps or protect historical attribution. Implement serialized gap allocation plus record incarnation isolation.

- [ ] Add tests for minimum gaps, copy, deletion, fresh machine credentials, stale jobs/probes and retained historical attribution.
- [ ] Add database allocation mutex rows and immutable per-incarnation UUIDs. Wrap model insertion and deletion in database transactions; batch deletion must invoke model lifecycle.
- [ ] Preserve per-user old traffic/domain attribution with incarnation keys. Archive retired native server stats separately before reuse; never clear user totals. Reject delayed native server statistic writes from retired incarnations.
- [ ] Invalidate runtime cache and probe tasks on resource replacement. Authenticate WebSocket machine connections against their original credential on every message and before server pushes.
- [ ] Run local portable checks and pinned overlay validation; run full container CI. Review the diff before publishing and merging to the user's repository.

Ruling: no existing resource ID changes. New records always get fresh authentication material and incarnation identity even when their numeric ID is reused. Archived native node stats remain retained in a database archive rather than being attributed to a replacement node.
