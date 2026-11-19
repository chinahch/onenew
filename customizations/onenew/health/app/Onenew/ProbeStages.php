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
        foreach ($outs as $index=>$out) {
            $id = $out['tag'] ?? '';
            $badId = !is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $id) || isset($landings[$id]);
            if ($badId) $id = 'legacy_invalid_'.$index;
            $landingKernel = $kernel;
            if (array_key_exists($id, $variants)) {
                $out = $variants[$id];
            } else {
                $settings = $out['settings'] ?? [];
                if (isset($settings['vnext']) || isset($settings['servers'])) $landingKernel = 'xray';
                elseif (isset($settings['server']) || isset($settings['server_port'])) $landingKernel = 'singbox';
            }
            $landing = ['id'=>$id, 'name'=>mb_strcut(preg_replace('/[\x00-\x1f\x7f]/', '', $names[$id] ?? $id), 0, 200, 'UTF-8'), 'kernel_type'=>$landingKernel];
            try {
                if ($badId || !is_array($out)) throw new ProbeUnavailable('configuration_unavailable');
                $landing['outbound'] = ExitProbePayload::build($landingKernel === 'xray' ? 'xray-caddy' : 'singbox', $out)['outbound'];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException | ProbeUnavailable $e) {
                $landing['error_code'] = 'configuration_unavailable';
                $landing['outbound'] = new \stdClass();
            }
            $landings[$id] = $landing;
        }
        foreach (\App\Services\ServerService::getRoutes($node->route_ids ?? []) as $route) {
            if ($route->action === 'proxy' && !isset($landings[$route->action_value ?? ''])) throw new ProbeUnavailable('configuration_unavailable');
        }
        $source = (new ProbeOutboundBuilder())->cdnProbeHost($node) !== null ? 'cdn_roundtrip' : 'local_listener_roundtrip';
        return ['version'=>2, 'entry_source'=>$source, 'landings'=>array_values($landings)];
    }

    public static function accept(array $expected, ?array $stages, string $state, string $error, string $source = 'public_roundtrip', bool $fallbackExpected = false, ?int $expectedVersion = null): array
    {
        if ($stages === null) return ['health_state'=>'unknown', 'stage_error'=>'upgrade_required'];
        abort_unless($expectedVersion === null || ($stages['version'] ?? null) === $expectedVersion, 422, 'Unexpected stage version');
        if (($stages['version'] ?? null) === 2) return self::acceptV2($expected, $stages, $state, $error, $source, $fallbackExpected);
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
        if ($error === 'proxy_failed') $health = 'node_fault';
        elseif ($faults > 0) $health = $faults === count($expected) ? 'landing_fault' : 'partial_landing_fault';
        elseif (!$abnormal && $state === 'succeeded') $health = 'online';
        return ['health_state'=>$health, 'stages'=>['version'=>1, 'entry'=>$entry+['source'=>$source], 'landings'=>$landings]];
    }

    private static function executedFailure(array $r): bool
    {
        return $r['state'] === 'failed' && in_array($r['error_code'], ['transport_failed','proxy_failed','target_failed','timeout'], true);
    }

    private static function acceptV2(array $expected, array $stages, string $state, string $error, string $source, bool $fallbackExpected): array
    {
        $e = $stages['entry'] ?? [];
        abort_unless(in_array($source, ['cdn_roundtrip','local_listener_roundtrip'], true) && ($e['source'] ?? null) === $source && is_array($e['primary'] ?? null), 422, 'Invalid entry evidence');
        $primary = self::result($e['primary']);
        $entry = self::result($e);
        $fallback = isset($e['fallback']) ? self::result($e['fallback']) : null;
        abort_unless(!$fallback || ($fallbackExpected && $source === 'cdn_roundtrip' && self::executedFailure($primary)), 422, 'Invalid fallback evidence');
        $unexecutedFallback = $source === 'cdn_roundtrip' && $fallbackExpected && self::executedFailure($primary) && $fallback === null;
        abort_unless(!$unexecutedFallback || ($entry['state'] === 'expired' && $entry['error_code'] === 'expired'), 422, 'Missing fallback evidence');
        $effective = $unexecutedFallback ? $entry : ($fallback ?? $primary);
        abort_unless($entry === $effective && $entry['state'] === $state && $entry['error_code'] === $error, 422, 'Inconsistent entry evidence');
        $rows = $stages['landings'] ?? null;
        abort_unless(is_array($rows) && array_is_list($rows) && count($rows) === count($expected), 422, 'Incomplete landing evidence');
        $landings = []; $faults = 0; $unknown = false;
        foreach ($expected as $i=>$l) {
            abort_unless(($rows[$i]['id'] ?? null) === $l['id'], 422, 'Invalid landing evidence');
            $r = self::result($rows[$i]);
            $landings[] = ['id'=>$l['id'], 'name'=>$l['name']] + $r;
            $faults += (int) self::executedFailure($r);
            $unknown = $unknown || ($r['state'] !== 'succeeded' && !self::executedFailure($r));
        }
        $health = self::executedFailure($effective) ? 'node_fault' : ($faults ? ($faults === count($expected) ? 'landing_fault' : 'partial_landing_fault') : (!$unknown && $state === 'succeeded' ? 'online' : 'unknown'));
        $evidence = $entry + ['source'=>$source, 'primary'=>$primary];
        if ($fallback) $evidence['fallback'] = $fallback;
        return ['health_state'=>$health, 'stages'=>['version'=>2,'entry'=>$evidence,'landings'=>$landings]];
    }

    private static function result(array $result): array
    {
        $state = $result['state'] ?? ''; $code = $result['error_code'] ?? '';
        abort_unless(in_array($state, ['succeeded','failed','expired','unsupported'], true) &&
            in_array($code, ['', 'transport_failed', 'proxy_failed','target_failed','timeout','configuration_unavailable','unsupported','expired','connector_only'], true) &&
            (($state === 'succeeded' && $code === '') || ($state === 'failed' && $code !== '') || ($state === 'expired' && $code === 'expired') || ($state === 'unsupported' && $code === 'unsupported')), 422, 'Invalid stage result');
        return ['state'=>$state, 'error_code'=>$code];
    }
}
