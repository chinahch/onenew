# Upgrade assistant memory and diagnostics

User requirement: future panel-triggered upgrades must work without manually stopping the assistant. Do not confuse lifetime upgrade lock with a failed transaction.

Evidence: native caddy archive member is ~64MiB. Current extract uses io.ReadAll, and copyFile uses full regular-file read. User cgroup records oom_kill=1, directory fsync succeeds; historical counter alone does not identify killed process. Current recover() runs everyloop and genericstderr hides errors, so interrupted alone cannot establishOOM.

Implementation:
1. Reproduce excess peakRSS on a fresh Linux helper extracting/replacing a realistic64MiB member. Stream bounded writes to same-directory private temporary files, fsync and atomicrename; preserve regularfile/symlink bounds, failedwrite cleanup, existing rollback guarantees. Assertboundedmemory and fullcontents/modes.
2. Add bounded, allowlisted operation diagnostics (stage/code only; never tokens/leases/URLs/rawerrors) and distinguish journalpersistence failure from interruptedprocess recovery where provable. No unsafe auto-retry of mutations.
3. Add one-time assistant-only refresh path that preserves core/config/service and safely stops/restarts helper with journal checks/recovery, so users can bootstrap corrected assistant once. Keep panel upgrades independent of helper process and no lifetime-lock deletion.
4. Test OpenRC/systemd transactions and rollback, lowmemory process, installerassistantrefresh, botharchitectures. Independent review; immutable nativev0.1.16 with updated assistantversion, actualv15->v16 validation, checksums/catalog pinned afterverifiedartifacts. Publish following greenCI.
