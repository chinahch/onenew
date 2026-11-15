# Energy and per-node aggregate bandwidth implementation

Approved design: outputs/onenew-能量与节点限速设计.md. Preserve the existing customer shell and native accounting semantics.

1. Finish and publish permanent restored-version fix separately; exact-head CI must exercise ordinary rebuild and second migration export. Provide archive upgrade command for the existing server.
2. Add energy request schema and controller under customizations/onenew/energy; load through runtime provider and install overlay. Requests use exact decimal strings converted to integer cents, idempotent user request IDs and atomic admin decisions. Reuse native user.balance only. Add framework tests for ownership, invalid precision/amount, cancellation, approval, rejection and repeated decisions.
3. Add customer Energy page and navigation, approval dialog for renewals and shared energy formatter. Replace customer-facing currency symbols and financial unit labels across actual Vue components; preserve fields/API contract. Test parsing/formatting/state and typecheck/build.
4. Add native admin EnergyRequests page through pinned bundle patch and encrypted authenticated API. Display pending/history and review confirmation; never adjust balance from browser directly. Test actual upstream anchors and API calls.
5. Trace native per-node kernel data paths and model protocol data. Implement aggregate directional token buckets shared by all users on a node, with atomic rate updates and independent nodes. Add panel persistence/validation/native payload, node editor field and capability checks. Reject unsupported kernels instead of saving ineffective limits. Add kernel and model regression coverage and real multi-connection rate validation.
6. Build/update caddy release with amd64/arm64 integrity artifacts; publish frontend/backend/native changes only after exact-head CI, review and source-tree verification. Document one-time node upgrade for enforcement.
