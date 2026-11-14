"""Apply the Onenew native config hot-application overlay to prepared source."""
import argparse
import json
from pathlib import Path

FEATURE = 'onenew-hot-apply-v1'

def apply(source):
    here = Path(__file__).resolve().parent
    target = source / 'internal/kernel/singbox'
    file = target / 'singbox.go'
    text = file.read_text(encoding='utf-8')
    if 's.hotApplyOutbounds(' not in text:
        old = '\t// Update routing rules\n'
        if text.count(old) != 1:
            raise ValueError('sing-box routing reload anchor changed')
        text = text.replace(old, '\tif err := s.hotApplyOutbounds(router, opts, nodeConfig, users, tls); err != nil {\n\t\treturn err\n\t}\n\n' + old)
        text = text.replace('nlog.Core().Debug("routing reload failed", "error", err)', 'return fmt.Errorf("reload routing: %w", err)')
        old = 'configChanged := tlsChanged || s.nodeConfig == nil || kernel.ComputeHash(nodeConfig, users) != kernel.ComputeHash(s.nodeConfig, s.users)'
        if text.count(old) != 1:
            raise ValueError('sing-box inbound reload anchor changed')
        text = text.replace(old, 'configChanged := tlsChanged || s.nodeConfig == nil || s.hotApplyInboundChanged(nodeConfig, users, tls)')
        file.write_text(text, encoding='utf-8', newline='\n')
    text = file.read_text(encoding='utf-8')
    if 's.hotApplyCloseOldExit(' not in text:
        text = text.replace('\t// Trackers remain registered on the Router', '\ts.hotApplyCloseOldExit(nodeConfig, users, tls)\n\n\t// Trackers remain registered on the Router')
        file.write_text(text, encoding='utf-8', newline='\n')
    tracker_file = target / 'conntracker.go'
    tracker = tracker_file.read_text(encoding='utf-8')
    if 'connMap map[string]io.Closer' not in tracker:
        tracker = tracker.replace('connMap map[string]net.Conn', 'connMap map[string]io.Closer').replace('make(map[string]net.Conn)', 'make(map[string]io.Closer)')
        # Store the wrappers so close decrements counters and releases map entries.
        tracker = tracker.replace('return &trackedConn{', 'tracked := &trackedConn{', 1)
        anchor = '\t\tctx:      ctx,\n\t}\n}'
        if tracker.count(anchor) != 1:
            raise ValueError('TCP tracker anchor changed')
        tracker = tracker.replace(anchor, '\t\tctx:      ctx,\n\t}\n\tt.usersMu.Lock()\n\tt.connMap[connID] = tracked\n\tt.usersMu.Unlock()\n\treturn tracked\n}')
        tracker = tracker.replace('return &trackedPacketConn{', 'tracked := &trackedPacketConn{', 1)
        anchor = '\t\tctx:        ctx,\n\t}\n}'
        if tracker.count(anchor) != 1:
            raise ValueError('UDP tracker anchor changed')
        tracker = tracker.replace(anchor, '\t\tctx:        ctx,\n\t}\n\tt.usersMu.Lock()\n\tt.connMap[connID] = tracked\n\tt.usersMu.Unlock()\n\treturn tracked\n}')
        tracker_file.write_text(tracker, encoding='utf-8', newline='\n')
    for name in ['hot_apply_singbox.go', 'hot_apply_singbox_test.go']:
        (target / ('onenew_' + name)).write_bytes((here / name).read_bytes())
    machine_file = source / 'internal/machine/machine.go'
    machine = machine_file.read_text(encoding='utf-8')
    if 'configFetched := false' not in machine:
        begin = machine.index('\tnodeCfg := o.cfg.ExpandMachineNode(mn.ID, mn.Type)')
        end = machine.index('\t// Save the actual per-node kernel', begin)
        resolution = machine[begin:end]
        resolution = resolution.replace('\t// Pre-fetch node config', '\tconfigFetched := false\n\t// Pre-fetch node config')
        resolution = resolution.replace('if cfgSnapshot, err := perNodeClient.GetConfig(); err == nil && cfgSnapshot != nil {', 'if cfgSnapshot, err := perNodeClient.GetConfig(); err == nil && cfgSnapshot != nil {\n\t\tconfigFetched = true')
        machine = machine[:begin] + machine[end:]
        anchor = 'func (o *Orchestrator) startNode(ctx context.Context, mn panel.MachineNode) {\n'
        machine = machine.replace(anchor, anchor + resolution)
        machine = machine.replace('h.requestedKernel == mn.KernelType {', 'h.requestedKernel == mn.KernelType && (!configFetched || h.kernel.Type == nodeCfg.Kernel.Type) {')
        anchor = '\ttranslated, err := controlplane.TranslateWSEvent(event, kernelCfg)'
        machine = machine.replace(anchor, '''
    // Kernel selection belongs to the machine lifecycle, before normalization
    // against the old kernel can reject a newly selected transport.
    if event.Config != nil {
        requested := kernelCfg.Type
        if event.Config.KernelType == "xray" || event.Config.KernelType == "singbox" { requested = event.Config.KernelType }
        requested = model.ResolveKernelForTransport(event.Config.Network, requested)
        if requested != kernelCfg.Type || (event.Config.Protocol != "" && event.Config.Protocol != h.discoveredType) {
            go o.rediscover(o.runCtx)
            return
        }
    }
''' + anchor)
        machine_file.write_text(machine, encoding='utf-8', newline='\n')
    service_file = source / 'internal/service/service.go'
    service = service_file.read_text(encoding='utf-8')
    failed_start = '\tif err := s.kernel.Start(nc, users, s.cert.TLSCert()); err != nil {\n'
    if failed_start + '\t\ts.lastConfigHash = ""\n' not in service:
        if service.count(failed_start) != 1:
            raise ValueError('kernel failed-start anchor changed')
        service = service.replace(failed_start, failed_start + '\t\ts.lastConfigHash = ""\n')
    if 'm["onenew_runtime"]' not in service:
        anchor = '\tm := make(map[string]interface{})\n'
        service = service.replace(anchor, anchor + '''\tm["onenew_runtime"] = true
    switch s.kernel.Name() {
    case "xray": m["kernel_type"] = "xray"
    case "sing-box": m["kernel_type"] = "singbox"
    }
''')
    service_file.write_text(service, encoding='utf-8', newline='\n')
    for package in ['machine', 'service']:
        (source / f'internal/{package}/onenew_hot_apply_test.go').write_bytes((here / f'hot_apply_{package}_test.go').read_bytes())
    manifest_file = source / 'build-source.json'
    manifest = json.loads(manifest_file.read_text(encoding='utf-8'))
    features = manifest.setdefault('features', [])
    if FEATURE not in features:
        features.append(FEATURE)
    manifest_file.write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', required=True, type=Path)
    apply(parser.parse_args().source.resolve())
