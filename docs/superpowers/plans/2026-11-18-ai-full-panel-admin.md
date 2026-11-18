# Complete Panel Administration Assistant Implementation Plan

> **For agentic workers:** Use superpowers:subagent-driven-development to implement and review tasks.

**Goal:** Administrator chat can discover and invoke the installed panel's real administrator management interfaces instead of five hard-coded actions. No model-generated shell or arbitrary external URL execution.

**Architecture:** A native route capability registry discovers only first-party installed routes protected by admin middleware. Explicit read allowlist prevents GET side effects. All other native endpoints require immutable confirmation and durable audit before dispatch under the requesting administrator; no credentials given to model. Existing source tools remain actual code reads and confirmed repository PR writes. Separate node template operation generates new REALITY material and checks port conflicts. Failures return bounded reason codes rather than generic catch-all.

**User steering:** User repeatedly requested full panel admin permissions and opted out of further design questions. Preserve confirmation for mutation, no surprise production execution during development. Do not claim shell/node log/deployment checks exist without actual agent support.

## Tasks
- [ ] Add NativeAdminTools route discovery, bounded sanitized native reads, immutable native action preparation and explicit single-execution dispatch/audit; test native authorization/FormRequest/ownership/replay/secret filtering.
- [ ] Add node template REALITY fresh-key generation and short ID, inherited port collision validation, explicit enable option and truthful deployment result; preserve template and no landing cloning. Test.
- [ ] Classify task failures into safe reason codes and stage; test provider timeout/config/schema errors without raw error disclosure.
- [ ] Connect lazy admin catalog/read and native actions into operator engine + confirmation endpoint; render localized generic native cards and result summaries without raw secrets. Test.
- [ ] Independent review, frontend + PHP/container CI; merge/release only after passing.

## Native gateway boundaries
First-party admin controller routes only, never AI recursive endpoints, passport/user/server-agent/public routes or caller-supplied URL/controller. Route identity pinned in encrypted card; admin permissions rechecked at execute time. Read catalog paginated/max50 records, outputs bounded and recursively redacted (passwords, UUID, tokens, private keys, .env, API keys). All writes including unusual GET actions require confirmation. Native controller validators and observers remain authoritative. No retries after native dispatch begins if result uncertain. Audit logs store operation identity/status; no prompts/credentials. Dynamic path parameters must bind concrete scalar values; current native request/auth state restored in finally.
