"""Add shared per-node bandwidth without modifying the Go module cache."""
import argparse,json,subprocess,hashlib,shutil
from pathlib import Path
FEATURE='onenew-node-bandwidth-v1'
MODULE='github.com/xtls/xray-core'
def replace(text,old,new):
    if new in text:return text
    if text.count(old)!=1:raise ValueError('Bandwidth anchor changed: '+old[:90])
    return text.replace(old,new,1)
def apply(source,core=None):
    source=source.resolve();here=Path(__file__).resolve().parent
    manifest=json.loads((source/'build-source.json').read_text())
    if manifest.get('upstream_commit')!='0a29338e1f102a462363ce3527417029f89bab28' or 'onenew-panel-egress-v1' not in manifest.get('features',[]):raise ValueError('Apply pinned egress preparation first')
    if FEATURE in manifest.get('features',[]):return
    def patch(name,old,new):
        p=source/name;p.write_text(replace(p.read_text(encoding='utf-8'),old,new),encoding='utf-8',newline='\n')
    patch('internal/panel/types.go','type NodeConfig struct {','type NodeConfig struct {\n\tNodeSpeedLimitMbps int `json:"node_speed_limit_mbps,omitempty"`')
    patch('internal/model/types.go','type NodeSpec struct {','type NodeSpec struct {\n\tNodeSpeedLimitMbps int')
    patch('internal/model/panel.go','\t\tKernelType:          nc.KernelType,','\t\tNodeSpeedLimitMbps: nc.NodeSpeedLimitMbps,\n\t\tKernelType:          nc.KernelType,')
    patch('internal/model/panel.go','\t\tKernelType:          n.KernelType,','\t\tNodeSpeedLimitMbps: n.NodeSpeedLimitMbps,\n\t\tKernelType:          n.KernelType,')
    patch('internal/model/validate.go','\teffectiveKernelType :=','\tif n.NodeSpeedLimitMbps < 0 || n.NodeSpeedLimitMbps > 100000 { return fmt.Errorf("invalid node speed limit") }\n\teffectiveKernelType :=')
    patch('internal/kernel/kernel.go','configData, _ := json.Marshal(nc)','copyConfig := *nc\n\tcopyConfig.NodeSpeedLimitMbps = 0\n\tconfigData, _ := json.Marshal(&copyConfig)')
    for name in ['node_bandwidth.go','node_bandwidth_test.go']:(source/'internal/limiter'/('onenew_'+name)).write_bytes((here/name).read_bytes())
    for backend in ['xray','singbox']:(source/f'internal/kernel/{backend}/onenew_bandwidth_test.go').write_bytes((here/f'bandwidth_{backend}_test.go').read_bytes())
    imp='nodebandwidth "github.com/cedar2025/xboard-node/internal/limiter"'
    for backend,struct in [('xray','Xray'),('singbox','SingBox')]:
        patch(f'internal/kernel/{backend}/{backend}.go','"github.com/cedar2025/xboard-node/internal/model"','"github.com/cedar2025/xboard-node/internal/model"\n\t'+imp)
        patch(f'internal/kernel/{backend}/{backend}.go',f'type {struct} struct {{',f'type {struct} struct {{\n\tnodeBandwidth *nodebandwidth.NodeBandwidth')
    patch('internal/kernel/xray/xray.go','\tcurrentUsers := x.users','\tcurrentUsers := x.users\n\tif x.nodeBandwidth == nil { x.nodeBandwidth = nodebandwidth.NewNodeBandwidth() }\n\tbuckets := x.nodeBandwidth\n\tif x.nodeConfig != nil { buckets.SetRate(x.nodeConfig.NodeSpeedLimitMbps) }')
    patch('internal/kernel/xray/xray.go','\tbm.Reset()','\tbm.SetNodeLimiters(buckets.Up,buckets.Down)\n\tbm.Reset()')
    patch('internal/kernel/xray/xray.go','if same {\n\t\tx.users = users\n\t\tx.mu.Unlock()','if same {\n\t\tx.users = users\n\t\tx.nodeConfig = nodeConfig\n\t\tx.mu.Unlock()\n\t\tx.updateBandwidthLimits(users)')
    patch('internal/kernel/singbox/singbox.go','\ts.connTracker = NewConnTracker(s.cfg.AuditNodeID)','\tif s.nodeBandwidth == nil { s.nodeBandwidth = nodebandwidth.NewNodeBandwidth() }\n\ts.nodeBandwidth.SetRate(nodeConfig.NodeSpeedLimitMbps)\n\ts.connTracker = NewConnTracker(s.cfg.AuditNodeID)\n\ts.connTracker.nodeBandwidth = s.nodeBandwidth')
    patch('internal/kernel/singbox/singbox.go','\tnlog.Core().Debug("sing-box reloaded", "users", len(users))','\tif s.nodeBandwidth != nil { s.nodeBandwidth.SetRate(nodeConfig.NodeSpeedLimitMbps) }\n\tnlog.Core().Debug("sing-box reloaded", "users", len(users))')
    patch('internal/kernel/singbox/conntracker.go','"github.com/cedar2025/xboard-node/internal/nlog"','"github.com/cedar2025/xboard-node/internal/nlog"\n\t'+imp)
    patch('internal/kernel/singbox/conntracker.go','type ConnTracker struct {','type ConnTracker struct {\n\tnodeBandwidth *nodebandwidth.NodeBandwidth')
    patch('internal/kernel/singbox/conntracker.go','\tn, err := c.Conn.Read(b)','\tn, err := c.Conn.Read(b)\n\tif n>0 { if waitErr:=c.tracker.nodeBandwidth.Wait(c.ctx,true,n);waitErr!=nil{return n,waitErr} }')
    patch('internal/kernel/singbox/conntracker.go','\tn, err := c.Conn.Write(b)','\tif err:=c.tracker.nodeBandwidth.Wait(c.ctx,false,len(b));err!=nil{return 0,err}\n\tn, err := c.Conn.Write(b)')
    patch('internal/kernel/singbox/conntracker.go','\tdest, err := c.PacketConn.ReadPacket(buffer)','\tdest, err := c.PacketConn.ReadPacket(buffer)\n\tif err==nil {if waitErr:=c.tracker.nodeBandwidth.Wait(c.ctx,true,buffer.Len());waitErr!=nil{return dest,waitErr}}')
    patch('internal/kernel/singbox/conntracker.go','\terr := c.PacketConn.WritePacket(buffer, dest)','\tif err:=c.tracker.nodeBandwidth.Wait(c.ctx,false,buffer.Len());err!=nil{return err}\n\terr := c.PacketConn.WritePacket(buffer, dest)')
    for cls in ['trackedConn','trackedPacketConn']:
        for direction in ['Reader','Writer']:
            patch('internal/kernel/singbox/conntracker.go',f'func (c *{cls}) {direction}Replaceable() bool {{ return true }}',f'func (c *{cls}) {direction}Replaceable() bool {{ return c.tracker == nil || c.tracker.nodeBandwidth == nil }}')
    for method,rettype in [('UnwrapReader','io.Reader'),('UnwrapWriter','io.Writer'),('UnwrapPacketReader','N.PacketReader'),('UnwrapPacketWriter','N.PacketWriter')]:
        cls='trackedPacketConn' if 'Packet' in method else 'trackedConn';old=f'func (c *{cls}) {method}() ({rettype}, []N.CountFunc) {{'
        writer='newBandwidthPacketWriter(c)' if method=='UnwrapPacketWriter' else f'struct{{{rettype}}}{{c}}'
        patch('internal/kernel/singbox/conntracker.go',old,old+f'\n\tif c.tracker != nil && c.tracker.nodeBandwidth != nil {{ return {writer}, nil }}')
    for name in ['udp_headroom.go','udp_headroom_test.go']:(source/'internal/kernel/singbox'/('onenew_'+name)).write_bytes((here/name).read_bytes())
    patch('internal/service/service.go','m["onenew_runtime"] = true','m["onenew_runtime"] = true\n\tm["onenew_node_bandwidth_v1"] = s.kernel.Name()=="xray" || s.kernel.Name()=="sing-box"')
    local_core=source/'.onenew-xray-core'
    if core is None and local_core.exists():core=local_core
    if core is None:
        info=json.loads(subprocess.check_output(['go','mod','download','-json',MODULE],cwd=source,text=True));core=Path(info['Dir'])
    core=core.resolve()
    if core!=local_core:
        if not local_core.exists():shutil.copytree(core,local_core,copy_function=shutil.copyfile)
        core=local_core
    mod=source/'go.mod';text=mod.read_text()
    old='replace github.com/xtls/xray-core => github.com/cedar2025/Xray-core v0.0.0-20260409213332-f47935539965'
    text=replace(text,old,'replace github.com/xtls/xray-core => ./.onenew-xray-core');mod.write_text(text,encoding='utf-8',newline='\n')
    name='features/bandwidth/bandwidth.go';text=(core/name).read_text()
    text=replace(text,'\tReset()','\tSetNodeLimiters(up,down *rate.Limiter)\n\tGetNodeLimiter(upload bool)*rate.Limiter\n\tReset()');text=replace(text,'type Instance struct {','type Instance struct {\n\tnodeUp,nodeDown *rate.Limiter')
    text+='\nfunc(m *Instance)SetNodeLimiters(up,down *rate.Limiter){m.mu.Lock();defer m.mu.Unlock();m.nodeUp=up;m.nodeDown=down}\nfunc(m *Instance)GetNodeLimiter(upload bool)*rate.Limiter{m.mu.RLock();defer m.mu.RUnlock();if upload{return m.nodeUp};return m.nodeDown}\n';files={name:text}
    name='app/dispatcher/default.go';text=(core/name).read_text()
    for old,new in [('inboundLink.Writer, d.bandwidth, user)','inboundLink.Writer, d.bandwidth, user,true)'),('outboundLink.Writer, d.bandwidth, user)','outboundLink.Writer, d.bandwidth, user,false)'),('link.Writer, bandwidthManager, user)','link.Writer, bandwidthManager, user,false)')]:text=replace(text,old,new)
    text=replace(text,'link.Writer = applyBandwidthLimit(ctx, link.Writer, bandwidthManager, user,false)','link.Writer = applyBandwidthLimit(ctx, link.Writer, bandwidthManager, user,false)\n        if bandwidthManager != nil {\n            reader:=link.Reader.(*buf.TimeoutWrapperReader)\n            if lim:=bandwidthManager.GetUserLimiter(user.Email);lim!=nil{reader.Reader=&RateLimitReader{Reader:reader.Reader,Limiter:lim,Context:ctx}}\n            if lim:=bandwidthManager.GetNodeLimiter(true);lim!=nil{reader.Reader=&RateLimitReader{Reader:reader.Reader,Limiter:lim,Context:ctx}}\n        }')
    text=replace(text,'user *protocol.MemoryUser) buf.Writer {','user *protocol.MemoryUser,upload ...bool) buf.Writer {')
    a=text.index('\tlim := limiter.GetUserLimiter(user.Email)',text.index('func applyBandwidthLimit'));z=text.index('\n}',a)
    text=text[:a]+'''\tif lim:=limiter.GetUserLimiter(user.Email);lim!=nil{writer=&RateLimitWriter{Writer:writer,Limiter:lim,Context:ctx}}
    if lim:=limiter.GetNodeLimiter(len(upload)>0&&upload[0]);lim!=nil{if si:=session.InboundFromContext(ctx);si!=nil{si.CanSpliceCopy=3};writer=&RateLimitWriter{Writer:writer,Limiter:lim,Context:ctx}}
    return writer'''+text[z:];files[name]=text
    name='app/dispatcher/ratelimit.go';text=(core/name).read_text();a=text.index('\t\tif err := w.Limiter.WaitN');z=text.index('\n\t}\n\treturn w.Writer',a)
    text=text[:a]+'''\t\tremaining:=int(mb.Len())
        for remaining>0{size:=remaining;if size>w.Limiter.Burst(){size=w.Limiter.Burst()};if err:=w.Limiter.WaitN(ctx,size);err!=nil{buf.ReleaseMulti(mb);return err};remaining-=size}'''+text[z:];text+='\n// RateLimitReader stays inside TimeoutWrapperReader so sniffing keeps its concrete timeout interface.\ntype RateLimitReader struct { Reader buf.Reader; Limiter *rate.Limiter; Context context.Context }\nfunc(r *RateLimitReader)ReadMultiBuffer()(buf.MultiBuffer,error){\n mb,err:=r.Reader.ReadMultiBuffer();ctx:=r.Context;if ctx==nil{ctx=context.Background()};remaining:=int(mb.Len())\n for remaining>0{size:=remaining;if size>r.Limiter.Burst(){size=r.Limiter.Burst()};if e:=r.Limiter.WaitN(ctx,size);e!=nil{buf.ReleaseMulti(mb);return nil,e};remaining-=size};return mb,err\n}\nfunc(r *RateLimitReader)Close()error{return common.Close(r.Reader)}\nfunc(r *RateLimitReader)Interrupt(){common.Interrupt(r.Reader)}\n';files[name]=text
    for name,text in files.items():(core/name).write_text(text,encoding='utf-8',newline='\n')
    manifest.setdefault('features',[])
    if FEATURE not in manifest['features']:manifest['features'].append(FEATURE)
    if 'onenew-udp-headroom-v1' not in manifest['features']:manifest['features'].append('onenew-udp-headroom-v1')
    for path in [Path(__file__),here/'node_bandwidth.go',here/'node_bandwidth_test.go',here/'bandwidth_xray_test.go',here/'bandwidth_singbox_test.go',here/'udp_headroom.go',here/'udp_headroom_test.go']:
        manifest.setdefault('customization_sha256',{})['onenew/'+path.name]=hashlib.sha256(path.read_bytes()).hexdigest()
    (source/'build-source.json').write_text(json.dumps(manifest,indent=2)+'\n')
if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--source',type=Path,required=True);p.add_argument('--xray-core',type=Path);a=p.parse_args();apply(a.source,a.xray_core)
