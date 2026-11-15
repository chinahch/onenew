# Unified Landing Library Implementation Plan

Goal: reusable encrypted landings managed from node toolbar, selectable in node main editor, old advanced parser removed, duplicate nodes have no landings.
Architecture: library encrypted payload stores original link and strict parser-produced kernel variants. Backend validates envelope and link endpoint, compiles per-node native arrays from saved bindings using actual runtime; model saving handles atomic node submission and observer hot apply. Existing native JSON retained when bindings=null. Bindings=[] explicitly clears custom landings.
Stack: Laravel/SQLite/Redis existing native observer; React injected into pinned bundle.

- [ ] Backend: landing records CRUD/admin permissions/encryption, node binding migration/casts/validation/saving hook, atomic referenced-node regeneration, deletion protection; real feature regressions and CI registration.
- [ ] Frontend: standalone toolbar library CRUD, strict variants from existing parseLink, form fields binding global/services/domains, saved state, direct-connect clearing and legacy native JSON preservation; reuse LandingProbe per selection.
- [ ] Integration: remove advanced Outbounds+Routes tab, inject new fields/button, clone removes binding/native routes/outbounds/route IDs and stale identity/telemetry; production build tests.
- [ ] Review and verify: backend CI, pinned bundle DOM render desktop/mobile, cancellation/reopen tests, per-core incompatible fail closed, export committed delta and publish after all checks pass.

Ruling: node stores library binding rules; library variants are configuration entered by authenticated admin, accepted only through existing ExitProbePayload schema validation, endpoint matched to original link metadata. Unsupported variants absent, node assignment refuses absent core. Actual parser stays existing audited JavaScript parser rather than adding a second divergent parser. All credentials encrypted at rest in library. Existing server native JSON remains current behavior to preserve compatibility.

User steering: toolbar add landing, main node form selector, remove advanced parser tab. Copy endpoint clears native custom arrays/library bindings/route IDs and parent ID; copied node disabled to prevent same-port runtime collision. Keep original unchanged.
