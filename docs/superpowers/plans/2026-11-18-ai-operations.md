# AI Operations Assistant Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans task-by-task.

**Goal:** Admin-only whole-panel feature Q&A and diagnostic assistant with full sidebar page and shared floating drawer.
**Architecture:** First-party versioned knowledge from verified project code; allowlisted live summaries; immutable admin-owned expiring preview before explicit external API send. Compatible Chat Completions backend only; encrypted settings; no arbitrary commands/tools/config writes. Mutations navigate to existing reviewed native confirmation pages.
**Tech Stack:** Existing Laravel overlays, cache locks, Http client, React pinned-bundle overlay, Node tests, container PHPUnit.
**Spec:** User approved left sidebar full page + floating shortcut; custom API/model/key entered later; whole-panel questions (nodes, machines, landing, users, plans, orders, energy, backups, upgrades, subscriptions). Existing outputs/onenew-AI诊断设计.md expanded by this plan.

## Contracts
- Prefix `/api/onenew/admin/ai`, api+admin auth, no request/body audit logging. Independent per-admin throttles for external calls.
- GET/POST config: enabled, endpoint (HTTPS compatible base `/v1` or full `/chat/completions`), model, key_configured; write api_key, blank retains, clear_key explicit. Secret Crypt encrypted. Endpoint HTTPS, no userinfo/query/fragments, outbound public addresses only, no redirects, TLS verification; cap response/time. Never expose provider errors or key.
- POST preview: {question<=4000 chars,scope:panel|nodes|users|orders|backups|upgrades,node_id?:int,history?:[{role:user|assistant,content:string}]<=8}. Reply {preview_id,question,scope,context,knowledge,sources,expires_at}; sanitized snapshot/history tied to admin cache for 10m. No API call here.
- POST chat: {preview_id,request_id:UUID,confirmed:true}. Reuse immutable preview snapshot; idempotent result/lock, no duplicate provider charge. Reply {answer,model,usage,sources,generated_at}. Text only, bounded output. Same preview single-send; retry same request ID returns previous result; no automatic external retry.
- POST test: saved configuration only, minimal hello, no panel/user data; reports sanitized success/failure.
- GET options: bounded node IDs with generic labels only, no credentials; selectors choose node without copying raw settings.
- Runtime context contains timestamps/revision/counts, selected node safe protocol/ports/TLS switches, whitelisted probe fields; fleet bounded IDs/statuses; users/plans/orders counts only, energy pending count, latest backup/restore/upgrade statuses enums. No email, token, UUID, names/notes, cert/key, links, arbitrary logs or raw config. Admin can identify node by ID. Data collection is read-only (no create/dispatch/poll probe).
- Knowledge entries include real source paths and explicit version. Cover pricing/limits/energy, renewal, subscription eligibility, audit/background filters, staged health, immediate config polling, library/egress, migrations/restores/cloud scheduling, upgrade bootstrap. Never pretend a snapshot/code explanation proves client connectivity. No runtime source eval, retrieval of arbitrary files or web browsing.
- Chat model receives system instruction + fixed knowledge + JSON data block + sanitized history/question; untrusted data cannot grant execution. UI shows answers as escaped plain text. API Key never browser-side inference. No raw conversations persisted to DB/logs; in-memory shared page/drawer history + explicit clear.

## Tasks
- [ ] Backend settings/provider/routes/preview/chat/tests: own ai-operations module + install.php/provider/CI registration.
- [ ] UI full page/settings/drawer shared memory, safe preview/confirm UX, route/sidebar shell patch, styles/tests/build.
- [ ] Context/knowledge module and tests: root owns Knowledge.php, Context.php, Redactor.php and related files; backend consumes public APIs declared below.
- [ ] Integrate, full tests, independent review, resolve findings, CI, publish PR merge; provide pinned restored-panel upgrade command.

## Shared PHP interfaces
Namespace `App\Onenew\AiOperations`:
- `Context::snapshot(string $scope, ?int $nodeId=null): array`
- `Knowledge::entries(string $scope, string $question=''): array` returns [{id,title,text,sources:[relative repo paths]}].
- `Redactor::text(string $input,array $secrets=[]): string` bounded safe text, redacts credential URLs, bearer/JWT and explicit secrets.
- Context/Knowledge inject via container autowire, backend may own Config/Provider/Controller/Service.

## Verification gates
Auth for all methods, secret write/read/blank/clear; preview no HTTP; scope validation, no credential snapshots, injection cannot change system instruction; pinned snapshots admin-bound/expiry/idempotency; provider auth/transport and error redaction with Http fakes; zero real account requirement. UI route/sidebar/global launcher actual upstream patch integration, keyboard/close/responsive layout, never auto-send on mount or preview. External provider live smoke deferred until owner fills real API; don't claim it tested.
