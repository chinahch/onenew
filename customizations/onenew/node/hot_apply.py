"""Apply the Onenew native config hot-application overlay to prepared source."""
import argparse
import hashlib
import json
from pathlib import Path

FEATURE = 'onenew-hot-apply-v1'

def apply(source):
    manifest_file = source / 'build-source.json'
    manifest = json.loads(manifest_file.read_text(encoding='utf-8'))
    if manifest.get('upstream_commit') != '0a29338e1f102a462363ce3527417029f89bab28':
        raise ValueError('Requires pinned Onenew native source')
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
    text = file.read_text(encoding='utf-8')
    if 's.hotApplyRemoveStaleInbounds(' not in text:
        anchor = '\tconfigChanged := tlsChanged || s.nodeConfig == nil || s.hotApplyInboundChanged(nodeConfig, users, tls)'
        if text.count(anchor) != 1:
            raise ValueError('sing-box stale inbound cleanup anchor changed')
        replacement = anchor + '''
	if configChanged {
		if err := s.hotApplyRemoveStaleInbounds(im, nodeConfig, users, tls); err != nil {
			return err
		}
	}'''
        file.write_text(text.replace(anchor, replacement), encoding='utf-8', newline='\n')
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
    topology_helper = '''// listenerTopologyChanged identifies node edits that cannot safely reuse a live listener.
func listenerTopologyChanged(previous, next *model.NodeSpec) bool {
	if previous == nil || next == nil {
		return false
	}
	return previous.Protocol != next.Protocol ||
		previous.Network != next.Network ||
		previous.ServerPort != next.ServerPort ||
		previous.ListenIP != next.ListenIP ||
		previous.Version != next.Version ||
		previous.Transport != next.Transport
}

'''
    if 'func listenerTopologyChanged(' not in service:
        anchor = '// applyChanges applies config changes to the kernel. User-only changes are\n'
        if service.count(anchor) != 1:
            raise ValueError('service listener topology helper anchor changed')
        service = service.replace(anchor, topology_helper + anchor)
    restart_failure = '''			nlog.Core().Warn(fmt.Sprintf("reload failed, restarting: %v", err))
			s.startKernel(s.lastConfig, s.lastUsers)'''
    safe_restart_failure = '''			nlog.Core().Warn(fmt.Sprintf("reload failed, restarting: %v", err))
			// Release old listeners before binding the updated protocol on the same port.
			s.kernel.Stop()
			previousConfig := s.appliedState.Config
			previousUsers := append([]model.UserSpec(nil), s.appliedState.Users...)
			if !s.startKernel(s.lastConfig, s.lastUsers) && previousConfig != nil && len(previousUsers) > 0 {
				nlog.Core().Warn("updated config failed to start; restoring previously applied config")
				s.startKernel(previousConfig, previousUsers)
			}'''
    if safe_restart_failure not in service:
        if service.count(restart_failure) != 1:
            raise ValueError('service reload failure recovery anchor changed')
        service = service.replace(restart_failure, safe_restart_failure)
    topology_restart = '''	if configChanged && s.kernel.IsRunning() && listenerTopologyChanged(s.appliedState.Config, s.lastConfig) {
		previousConfig := s.appliedState.Config
		previousUsers := append([]model.UserSpec(nil), s.appliedState.Users...)
		nlog.Core().Info("listener topology changed, restarting kernel")
		s.kernel.Stop()
		if !s.startKernel(s.lastConfig, s.lastUsers) && previousConfig != nil && len(previousUsers) > 0 {
			nlog.Core().Warn("updated config failed to start; restoring previously applied config")
			s.startKernel(previousConfig, previousUsers)
		}
		return
	}

'''
    if topology_restart not in service:
        anchor = '\t// If config changed, delegate to kernel.Reload. The kernel implementation\n'
        if service.count(anchor) != 1:
            raise ValueError('service listener topology restart anchor changed')
        service = service.replace(anchor, topology_restart + anchor)
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
    service_test_file = source / 'internal/service/service_test.go'
    service_test = service_test_file.read_text(encoding='utf-8')
    if 'reloadErr' not in service_test:
        anchor = '\tstartErr  error\n'
        if service_test.count(anchor) != 1:
            raise ValueError('service test reload-error field anchor changed')
        service_test = service_test.replace(anchor, anchor + '\treloadErr error\n')
    if 'startErrors' not in service_test:
        anchor = '\tstartErr  error\n'
        if service_test.count(anchor) != 1:
            raise ValueError('service test queued-start-error field anchor changed')
        service_test = service_test.replace(anchor, anchor + '\tstartErrors []error\n')
    if 'stopCalls' not in service_test or 'startWhileRunning' not in service_test:
        anchor = '\tstartCalls  int\n'
        if service_test.count(anchor) != 1:
            raise ValueError('service test stop-call field anchor changed')
        fields = ''
        if 'stopCalls' not in service_test:
            fields += '\tstopCalls int\n'
        if 'startWhileRunning' not in service_test:
            fields += '\tstartWhileRunning bool\n'
        service_test = service_test.replace(anchor, anchor + fields)
    service_test = service_test.replace(
        '\tf.startCalls++\n\tif f.startErr != nil {',
        '\tf.startCalls++\n\tf.startWhileRunning = f.startWhileRunning || f.running\n\tif f.startErr != nil {',
    )
    if 'if len(f.startErrors) > 0 {' not in service_test:
        anchor = '\tf.startCalls++\n\tf.startWhileRunning = f.startWhileRunning || f.running\n'
        if service_test.count(anchor) != 1:
            raise ValueError('service test queued-start-error behavior anchor changed')
        service_test = service_test.replace(anchor, anchor + '''\tif len(f.startErrors) > 0 {
		err := f.startErrors[0]
		f.startErrors = f.startErrors[1:]
		if err != nil {
			return err
		}
	}
''')
    service_test = service_test.replace(
        'func (f *fakeKernel) Stop()           { f.running = false }',
        'func (f *fakeKernel) Stop()           { f.stopCalls++; f.running = false }',
    )
    service_test = service_test.replace(
        '\t_, _, _ = nodeConfig, users, tls\n\treturn nil\n}\nfunc (f *fakeKernel) AddUsers',
        '\t_, _, _ = nodeConfig, users, tls\n\treturn f.reloadErr\n}\nfunc (f *fakeKernel) AddUsers',
    )
    service_test_file.write_text(service_test, encoding='utf-8', newline='\n')
    xray_file = source / 'internal/kernel/xray/xray.go'
    xray = xray_file.read_text(encoding='utf-8')
    old_stop = '''	if ld != nil {
		drainConns(ld, drainTimeout)
	}
	closeOld(inst, ld)
}'''
    new_stop = '''	// Explicit Stop is a lifecycle barrier: the replacement kernel may bind
	// these TCP/UDP ports as soon as this method returns. Do not defer closing
	// listeners to closeOld, which can drain active sessions for five minutes.
	if inst != nil {
		if err := inst.Close(); err != nil {
			nlog.Core().Warn("xray stop failed", "error", err)
		}
	}
	if ld != nil {
		ld.ResetConns()
	}
}'''
    if new_stop not in xray:
        if xray.count(old_stop) != 1:
            raise ValueError('xray synchronous stop anchor changed')
        xray_file.write_text(xray.replace(old_stop, new_stop), encoding='utf-8', newline='\n')
    (source / 'internal/kernel/xray/onenew_listener_lifecycle_test.go').write_bytes((here / 'listener_lifecycle_test.go').read_bytes())
    hub_file = source / '.onenew-xray-core/transport/internet/splithttp/hub.go'
    hub = hub_file.read_text(encoding='utf-8')
    if 'h3packet' not in hub:
        replacements = [
            ('\th3listener *quic.EarlyListener\n', '\th3listener *quic.EarlyListener\n\th3packet net.PacketConn\n'),
            ('\t\tl.h3listener, err = quic.ListenEarly(Conn, tlsConfig, quicConfig)\n\t\tif err != nil {\n',
             '\t\tl.h3packet = Conn\n\t\tl.h3listener, err = quic.ListenEarly(Conn, tlsConfig, quicConfig)\n\t\tif err != nil {\n\t\t\t_ = Conn.Close()\n'),
            ('func (ln *Listener) Close() error {\n',
             'func (ln *Listener) Close() error {\n\t// QUIC does not own the supplied PacketConn; explicitly release its UDP port.\n\tif ln.h3packet != nil {\n\t\tdefer ln.h3packet.Close()\n\t}\n'),
        ]
        for old, new in replacements:
            if hub.count(old) != 1:
                raise ValueError('XHTTP H3 UDP socket ownership anchor changed')
            hub = hub.replace(old, new)
        hub_file.write_text(hub, encoding='utf-8', newline='\n')
    for package in ['machine', 'service']:
        (source / f'internal/{package}/onenew_hot_apply_test.go').write_bytes((here / f'hot_apply_{package}_test.go').read_bytes())
    manifest_file = source / 'build-source.json'
    manifest = json.loads(manifest_file.read_text(encoding='utf-8'))
    features = manifest.setdefault('features', [])
    if FEATURE not in features:
        features.append(FEATURE)
    if 'onenew-listener-lifecycle-v1' not in features:
        features.append('onenew-listener-lifecycle-v1')
    manifest['onenew_listener_lifecycle_sha256'] = {
        name: hashlib.sha256((here / name).read_bytes()).hexdigest()
        for name in ['hot_apply.py', 'hot_apply_singbox.go', 'hot_apply_singbox_test.go',
                     'hot_apply_service_test.go', 'listener_lifecycle_test.go']
    }
    manifest_file.write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', required=True, type=Path)
    apply(parser.parse_args().source.resolve())
