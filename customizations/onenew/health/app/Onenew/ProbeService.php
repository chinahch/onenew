<?php

namespace App\Onenew;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class ProbeService
{
    public const INTERVAL = 60;
    public const RETRY_INTERVAL = 30;
    public const FRESHNESS = 600;
    public const LIFETIME = 180;

    public function __construct(private ProbeStore $store, private ProbeOutboundBuilder $builder)
    {
    }

    public function poll(ServerMachine $machine): array
    {
        return $this->store->locked((int) $machine->id, function () use ($machine) {
            if (class_exists(ExitProbeService::class) && app(ExitProbeService::class)->busy((int) $machine->id)) {
                return [];
            }
            $now = now()->timestamp;
            $active = $this->store->active((int) $machine->id);
            if ($active && $active['expires_at'] > $now) {
                $current = Server::find($active['node_id']);
                if ($current && (int) $current->machine_id === (int) $machine->id && $this->nodeAvailable($current)
                    && hash_equals($active['revision'], $this->revision($current))) {
                    return [];
                }
                $this->finish($active, 'pending', 'configuration_unavailable');
                $active = null;
            }
            if ($active) {
                $this->finish($active, 'expired', 'expired');
            }
            // Oldest scheduled node first: a broken or unsupported node cannot starve
            // the other listeners on the same machine. One task is ever leased.
            $nodes = ServerService::getMachineNodes($machine)->sortBy(function (Server $node) {
                $last = $this->store->latest((int) $node->id);
                $changed = $last && !hash_equals($last['revision'], $this->revision($node));
                return sprintf('%d:%012d:%012d', $changed ? 0 : 1, $last['created_at'] ?? 0, $node->id);
            });
            foreach ($nodes as $node) {
                $last = $this->store->latest((int) $node->id);
                if ($last && $this->nextAttemptAt($last) > $now
                    && $last['machine_id'] === (int) $machine->id
                    && hash_equals($last['revision'], $this->revision($node))) {
                    continue;
                }
                $task = [
                    'id' => (string) Str::uuid(), 'node_id' => (int) $node->id,
                    'machine_id' => (int) $machine->id, 'revision' => $this->revision($node),
                    'created_at' => $now, 'expires_at' => $now + self::LIFETIME,
                    'finished_at' => null, 'state' => 'pending', 'error_code' => '',
                ];
                if (!$this->nodeAvailable($node)) {
                    $this->finish($task, 'pending', 'configuration_unavailable');
                    return [];
                }
                $user = $this->eligibleUser($node);
                if (!$user) {
                    $this->finish($task, 'pending', 'no_eligible_users');
                    return [];
                }
                try {
                    $payload = $this->builder->build($node, $user);
                    $payload['health_stages'] = ProbeStages::payload($node, $payload['kernel_type'], $payload);
                    if ($payload['health_stages']['entry_source'] === 'cdn_roundtrip') $payload['health_stages']['fallback_outbound'] = $this->builder->build($node, $user, true)['outbound'];
                } catch (ProbeUnavailable $e) {
                    $this->finish($task, $e->getMessage() === 'unsupported' ? 'unsupported' : 'pending', $e->getMessage());
                    return [];
                }
                // Refuse oversized transport configuration before returning a bounded wire body.
                $raw = json_encode($payload, JSON_THROW_ON_ERROR);
                if (strlen($raw) > 65536) {
                    $this->finish($task, 'unsupported', 'configuration_unavailable');
                    return [];
                }
                $lease = bin2hex(random_bytes(32));
                $task['state'] = 'running';
                $task['entry_source'] = $payload['health_stages']['entry_source'];
                $task['expected_stages_version'] = $payload['health_stages']['version'];
                $task['expected_fallback'] = isset($payload['health_stages']['fallback_outbound']);
                $task['expected_landings'] = array_map(fn ($landing) => ['id'=>$landing['id'], 'name'=>$landing['name']], $payload['health_stages']['landings']);
                $task['user_id'] = (int) $user->id;
                $task['user_revision'] = hash('sha256', $user->uuid);
                $task['lease_hash'] = hash('sha256', $lease);
                $task['ciphertext'] = Crypt::encryptString($raw);
                $this->store->save($task);
                return [[
                    'id' => $task['id'], 'lease' => $lease, 'expires_at' => $this->iso($task['expires_at']),
                    'timeout_ms' => 120000, 'payload' => $payload,
                ]];
            }
            return [];
        });
    }

    private function nextAttemptAt(array $task): int
    {
        // A lost lease cannot finish after credentials expire. Retry at expiry;
        // completed failures get a short cooldown, successes keep the normal cadence.
        if ($task['state'] === 'running') {
            return (int) $task['expires_at'];
        }
        if ($task['state'] === 'expired') {
            return (int) $task['expires_at'];
        }
        if ($task['state'] === 'failed') {
            return (int) ($task['finished_at'] ?? $task['created_at']) + self::RETRY_INTERVAL;
        }
        return (int) $task['created_at'] + self::INTERVAL;
    }

    public function accept(ServerMachine $machine, string $id, array $result): void
    {
        $this->store->locked((int) $machine->id, function () use ($machine, $id, $result) {
            $task = $this->store->task($id);
            if (!$task || $task['machine_id'] !== (int) $machine->id || $task['state'] !== 'running' ||
                !hash_equals($task['lease_hash'] ?? '', hash('sha256', $result['lease']))) {
                abort(409, 'Probe lease unavailable');
            }
            if ($task['expires_at'] <= now()->timestamp) {
                $this->finish($task, 'expired', 'expired');
                abort(409, 'Probe lease unavailable');
            }
            $node = Server::find($task['node_id']);
            if (!$node || (int) $node->machine_id !== (int) $machine->id || !$this->nodeAvailable($node) ||
                !hash_equals($task['revision'], $this->revision($node))) {
                $this->finish($task, 'pending', 'configuration_unavailable');
                abort(409, 'Probe lease unavailable');
            }
            $user = $this->eligibleUser($node, $task['user_id']);
            if (!$user || !hash_equals($task['user_revision'], hash('sha256', $user->uuid))) {
                $this->finish($task, 'pending', 'account_unavailable');
                abort(409, 'Probe lease unavailable');
            }
            // Only allowlisted scalars survive. Free-form errors and diagnostics are
            // deliberately never cached, logged, or returned in a status projection.
            foreach (['latency_ms', 'ping_ms'] as $field) {
                if (isset($result[$field])) {
                    $task[$field] = $result[$field];
                }
            }
            $code = $result['error_code'] ?? '';
            $task = array_replace($task, ProbeStages::accept($task['expected_landings'] ?? [], $result['stages'] ?? null, $result['state'], $code, $task['entry_source'] ?? 'public_roundtrip', $task['expected_fallback'] ?? false, $task['expected_stages_version'] ?? 1));
            $state = $result['state'];
            // An unsupported/configuration result proves no proxy roundtrip happened.
            if (in_array($code, ['unsupported', 'configuration_unavailable', 'connector_only'], true)) {
                $state = $code === 'unsupported' ? 'unsupported' : 'pending';
            }
            $this->finish($task, $state, $code);
        });
    }

    public function status(Server $node): array
    {
        return $this->projectStatus($node, $this->store->latest((int) $node->id));
    }

    public function statuses(iterable $nodes): array
    {
        $list = is_array($nodes) ? $nodes : iterator_to_array($nodes);
        if ($list === []) {
            return [];
        }
        $latestByNode = $this->store->latestMany(array_map(fn (Server $node) => (int) $node->id, $list));
        $routeCache = [];
        foreach ($list as $node) {
            $routeIds = $node->route_ids ?? [];
            if ($routeIds !== []) {
                $key = json_encode($routeIds, JSON_THROW_ON_ERROR);
                if (!array_key_exists($key, $routeCache)) {
                    $routeCache[$key] = $this->resolveRoutes($routeIds);
                }
            }
        }
        $result = [];
        foreach ($list as $node) {
            $id = (int) $node->id;
            $result[$id] = $this->projectStatus($node, $latestByNode[$id] ?? null, $routeCache);
        }
        return $result;
    }

    private function projectStatus(Server $node, ?array $task, ?array $routeCache = null): array
    {
        $source = $this->builder->cdnProbeHost($node) !== null
            ? 'node machine · 原CDN优先，备用 ip.sb:443' : 'node machine · 本地监听自测';
        $pending = ['state' => 'pending', 'finished_at' => null, 'created_at' => null,
            'error_code' => '', 'source' => $source, 'latency_ms' => null, 'ping_ms' => null];
        if (!$this->nodeAvailable($node) || !$node->machine_id || !$node->machine?->is_active) {
            return array_replace($pending, ['error_code' => 'machine_unavailable']);
        }
        if (!$task || $task['machine_id'] !== (int) $node->machine_id || !hash_equals($task['revision'], $this->revision($node, $routeCache))) {
            return $pending;
        }
        $state = $task['state'];
        $code = $task['error_code'];
        if (($state === 'running' && $task['expires_at'] <= now()->timestamp) ||
            now()->timestamp - ($task['finished_at'] ?? $task['created_at']) > self::FRESHNESS) {
            $state = 'expired';
            $code = 'expired';
        }
        return [
            'state' => $state, 'finished_at' => $this->iso($task['finished_at']), 'created_at' => $this->iso($task['created_at']),
            'error_code' => $code, 'source' => $source,
            'latency_ms' => $task['latency_ms'] ?? null, 'ping_ms' => $task['ping_ms'] ?? null,
            'health_state' => in_array($state, ['succeeded','failed'], true) ? ($task['health_state'] ?? 'unknown') : 'unknown',
            'stage_error' => $task['stage_error'] ?? '', 'stages' => $task['stages'] ?? null,
        ];
    }

    private function finish(array $task, string $state, string $code): void
    {
        $task['state'] = $state;
        $task['error_code'] = $code;
        $task['finished_at'] = now()->timestamp;
        unset($task['ciphertext'], $task['lease_hash'], $task['user_revision'], $task['user_id']);
        $this->store->save($task);
    }

    private function eligibleUser(Server $node, ?int $id = null): ?User
    {
        // Reuse the native entitlement hook, then reload the selected account. No
        // synthetic UUID and no stale subscription snapshot can pass this boundary.
        $eligible = ServerService::getAvailableUsers($node);
        foreach ($eligible as $candidate) {
            $candidateId = (int) data_get($candidate, 'id');
            if ($id !== null && $candidateId !== $id) {
                continue;
            }
            $user = User::find($candidateId);
            if ($user && !$user->banned && $user->uuid &&
                (int) $user->u + (int) $user->d < (int) $user->transfer_enable &&
                ($user->expired_at === null || (int) $user->expired_at >= now()->timestamp) &&
                in_array((string) $user->group_id, array_map('strval', $node->group_ids ?? []), true)) {
                return $user;
            }
        }
        return null;
    }

    private function nodeAvailable(Server $node): bool
    {
        return (bool) $node->enabled && (!$node->transfer_enable || (int) $node->u + (int) $node->d < (int) $node->transfer_enable);
    }

    private function revision(Server $node, ?array $routeCache = null): string
    {
        $fields = ['onenew_identity', 'machine_id', 'enabled', 'type', 'host', 'port', 'server_port', 'parent_id',
            'node_speed_limit_mbps', 'onenew_landing_bindings', 'protocol_settings', 'group_ids', 'custom_outbounds', 'custom_routes', 'route_ids', 'cert_config'];
        // Invalidate cached unsupported results when the client builder changes.
        $values = ['probe_schema_version' => 10];
        foreach ($fields as $field) {
            $value = $node->getAttribute($field);
            // The native port column is VARCHAR without a model scalar cast:
            // create()/subscription mapping can leave 443 in memory, whereas a
            // database reload returns "443". Hash listener semantics rather than
            // incidental PDO/model scalar representations or absent empty arrays.
            $values[$field] = match ($field) {
                'machine_id', 'server_port', 'parent_id', 'node_speed_limit_mbps' => (int) $value,
                'enabled' => (bool) $value,
                'port' => is_numeric($value) ? (string) (int) $value : (string) $value,
                'type', 'host' => (string) $value,
                'group_ids', 'custom_outbounds', 'custom_routes', 'route_ids', 'cert_config' => $value ?? [],
                default => $value,
            };
        }
        $values['effective_egress'] = class_exists(MachineEgress::class) ? MachineEgress::forNode($node) : null;
        // Route IDs point to mutable records. Resolve the same collection as
        // buildNodeConfig, preserving its effective rule order rather than
        // independently sorting by node IDs or administrator labels. Fixed field
        // order makes the snapshot deterministic; metadata is not kernel input.
        $routeIds = $node->route_ids ?? [];
        if (empty($routeIds)) {
            $values['resolved_routes'] = [];
        } else {
            $routeKey = $routeCache !== null ? json_encode($routeIds, JSON_THROW_ON_ERROR) : null;
            $values['resolved_routes'] = ($routeKey !== null && array_key_exists($routeKey, $routeCache))
                ? $routeCache[$routeKey]
                : $this->resolveRoutes($routeIds);
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function resolveRoutes(array $routeIds): array
    {
        return ServerService::getRoutes($routeIds)
            ->map(fn ($route) => [
                'match' => $route->match,
                'action' => $route->action,
                'action_value' => $route->action_value,
            ])->values()->all();
    }

    private function iso(?int $timestamp): ?string
    {
        return $timestamp === null ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}

