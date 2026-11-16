<?php
namespace App\Onenew;

/** Bounded stage contract. Never persists native diagnostics or echoed names. */
final class ProbeStages
{
    public static function payload(\App\Models\Server $node, string $kernel, array $public = []): array
    {
        $outs = array_values(array_filter($node->custom_outbounds ?? [], fn ($out) => !in_array($out['protocol'] ?? '', ['direct','block','dns','freedom','blackhole'], true)));
        if (count($outs) > 8) throw new ProbeUnavailable('configuration_unavailable');
        $names = []; $variants = [];
        foreach ($node->onenew_landing_bindings ?? [] as $binding) {
            $row = \Illuminate\Support\Facades\DB::table('onenew_landings')->find($binding['landing_id']);
            if (!$row || !$row->enabled) throw new ProbeUnavailable('configuration_unavailable');
            $names['landing_'.$row->id] = (string) $row->name;
            $variants['landing_'.$row->id] = \App\Onenew\LandingLibrary\Library::payload($row)['variants'][$kernel === 'xray' ? 'xray-caddy' : 'singbox'] ?? null;
        }
        $landings = [];
        foreach ($outs as $out) {
            $id = $out['tag'] ?? '';
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $id) || isset($landings[$id])) throw new ProbeUnavailable('configuration_unavailable');
            // Compiled outbounds preserve protocol, credentials and TLS settings.
            if (array_key_exists($id, $variants)) {
                if (!is_array($variants[$id])) throw new ProbeUnavailable('configuration_unavailable');
                $out = $variants[$id];
            }
            try { $normalized = ExitProbePayload::build($kernel === 'xray' ? 'xray-caddy' : 'singbox', $out)['outbound']; }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { throw new ProbeUnavailable('configuration_unavailable'); }
            $landings[$id] = ['id'=>$id, 'name'=>mb_strcut(preg_replace('/[\x00-\x1f\x7f]/', '', $names[$id] ?? $id), 0, 200, 'UTF-8'), 'outbound'=>$normalized];
        }
        foreach (\App\Services\ServerService::getRoutes($node->route_ids ?? []) as $route) {
            if ($route->action === 'proxy' && !isset($landings[$route->action_value ?? ''])) throw new ProbeUnavailable('configuration_unavailable');
        }
        $host = data_get($public, 'outbound.settings.server') ?? data_get($public, 'outbound.settings.vnext.0.address');
        $source = in_array($host, ['127.0.0.1','::1','localhost'], true) ? 'local_listener_roundtrip' :
            ((new ProbeOutboundBuilder())->cdnProbeHost($node) !== null ? 'cdn_roundtrip' : 'public_roundtrip');
        return ['version'=>1, 'entry_source'=>$source, 'landings'=>array_values($landings)];
    }

    public static function accept(array $expected, ?array $stages, string $state, string $error, string $source = 'public_roundtrip'): array
    {
        if ($stages === null) return ['health_state'=>'unknown', 'stage_error'=>'upgrade_required'];
        abort_unless(($stages['version'] ?? null) === 1 && in_array($source, ['public_roundtrip','local_listener_roundtrip','cdn_roundtrip'], true) && ($stages['entry']['source'] ?? null) === $source, 422, 'Invalid probe stages');
        $entry = self::result($stages['entry']);
        abort_unless($entry['state'] === $state && $entry['error_code'] === $error, 422, 'Inconsistent entry result');
        $results = $stages['landings'] ?? null;
        abort_unless(is_array($results) && array_is_list($results) && count($results) === count($expected), 422, 'Incomplete probe stages');
        $landings = []; $success = 0; $faults = 0; $abnormal = false;
        foreach ($expected as $i=>$landing) {
            abort_unless(($results[$i]['id'] ?? null) === $landing['id'], 422, 'Invalid landing stage');
            $result = self::result($results[$i]);
            $landings[] = ['id'=>$landing['id'], 'name'=>$landing['name']] + $result;
            $success += (int) ($result['state'] === 'succeeded');
            $faults += (int) ($result['error_code'] === 'proxy_failed');
            $abnormal = $abnormal || ($result['state'] !== 'succeeded' && $result['error_code'] !== 'proxy_failed');
        }
        $health = 'unknown';
        if (!$abnormal && $state === 'succeeded') $health = $faults === 0 ? 'online' : ($success === 0 ? 'landing_fault' : 'partial_landing_fault');
        elseif (!$abnormal && $success === count($expected) && $error === 'proxy_failed') $health = 'node_fault';
        return ['health_state'=>$health, 'stages'=>['version'=>1, 'entry'=>$entry+['source'=>$source], 'landings'=>$landings]];
    }

    private static function result(array $result): array
    {
        $state = $result['state'] ?? ''; $code = $result['error_code'] ?? '';
        abort_unless(in_array($state, ['succeeded','failed','expired','unsupported'], true) &&
            in_array($code, ['', 'proxy_failed','target_failed','timeout','configuration_unavailable','unsupported','expired','connector_only'], true) &&
            (($state === 'succeeded' && $code === '') || ($state === 'failed' && $code !== '') || ($state === 'expired' && $code === 'expired') || ($state === 'unsupported' && $code === 'unsupported')), 422, 'Invalid stage result');
        return ['state'=>$state, 'error_code'=>$code];
    }
}
