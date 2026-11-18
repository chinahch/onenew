# Administrator Node Link Implementation Plan

Goal: Explicit administrator chat requests generate real per-user node links through native subscription logic and display a dedicated copy area, without weakening general secret redaction.

Design: admin-only POST /api/onenew/admin/ai/node-links accepts node_id and user_id or email. Verify native user availability and selected-node subscription eligibility, use existing native password/protocol generator including SS2022 and IPv6/Reality. Return a no-store response node_link with uri plus safe node/user IDs, source and generated_at evidence. No private key or service/API credentials. Links are not model output and never enter model context, persisted chat or export.

UI: Explicit local form plus narrowly recognized node-link requests bypass paid AI in both backends. Missing user opens form; bare email completes only active pending intent. No inferred identity from arbitrary history. Clear existing result when changing target or clearing chat. Clipboard copy with failure handling. Clearly distinguish native generated data from actual connectivity verification.

Tasks
- Backend tests first: native protocol generation, access/eligibility, ambiguity, admin auth, sensitive output and no-store.
- UI tests first: request/followup/form, direct API, evidence/copy, secret isolation and no model calls.
- Run frontend suite/build, PHP native feature CI, independent review, full CI then publish authorized branch.

User already requested implementation and has opted out of repeated design questions. Use current clean checkout on feature branch feat/ai-node-links. Preserve all existing OpenCode and chat functionality.
