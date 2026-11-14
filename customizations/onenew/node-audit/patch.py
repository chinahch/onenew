"""Add metadata hooks to a freshly prepared, pinned caddy source tree."""
from pathlib import Path
import hashlib, json, shutil, sys

root=Path(sys.argv[1]).resolve()
here=Path(__file__).resolve().parent

def patch(file, changes):
    p=root/file
    text=p.read_text(encoding='utf-8').replace('\r\n','\n')
    for old,new in changes:
        if text.count(old)!=1: raise RuntimeError(f'Unexpected node source anchor: {file}: {old[:70]}')
        text=text.replace(old,new,1)
    p.write_text(text,encoding='utf-8',newline='\n')

imp='"github.com/cedar2025/xboard-node/internal/onenewaudit"'
patch('internal/config/config.go', [('type KernelConfig struct {','type KernelConfig struct {\n\tAuditNodeID int `yaml:"-"`')])
patch('internal/service/service.go', [('func newService(cfg *config.Config, cp controlplane.ControlPlane) *Service {',
 'func newService(cfg *config.Config, cp controlplane.ControlPlane) *Service {\n\tcfg.Kernel.AuditNodeID = cfg.Panel.NodeID')])
patch('internal/machine/machine.go', [
 ('"github.com/cedar2025/xboard-node/internal/nlog"','"github.com/cedar2025/xboard-node/internal/nlog"\n\t'+imp),
 ('\to.runCtx = ctx','\to.runCtx = ctx\n\tauditCtx, stopAudit := context.WithCancel(ctx)\n\tdefer stopAudit()\n\tgo onenewaudit.Run(auditCtx, o.cfg.Panel.URL, o.cfg.Machine.Token, o.cfg.Machine.MachineID)')])
patch('internal/machine/caddy_enrollment_test.go', [
 ('\t\tif r.URL.Path == "/api/v3/server/probes" {', '''\t\tif r.URL.Path == "/api/v3/server/domain-audit/config" {
            if r.Method != "GET" || r.URL.Query().Get("machine_id") != "7" || r.URL.Query().Get("token") != "" || r.Header.Get("Authorization") != "Bearer fixture-private-machine-token" { badAuth.Store(true) }
            fmt.Fprint(w, `{"enabled":false,"retention_days":7}`)
            return
        }
\t\tif r.URL.Path == "/api/v3/server/probes" {''')])
patch('internal/kernel/xray/xray.go', [
 ('\tld := globalLimitDispatcher.Load()', '\tld := globalLimitDispatcher.Load()\n\tif ld != nil { ld.auditNodeID = x.cfg.AuditNodeID }')])
patch('internal/kernel/xray/dispatcher.go', [
 ('"github.com/cedar2025/xboard-node/internal/nlog"','"github.com/cedar2025/xboard-node/internal/nlog"\n\t'+imp),
 ('type LimitDispatcher struct {','type LimitDispatcher struct {\n\tauditNodeID int'),
 ('\treturn link, nil\n}', '\td.auditDestination(email, dest)\n\treturn link, nil\n}'),
 ('\treturn d.innerDisp.DispatchLink(ctx, dest, link)', '\terr = d.innerDisp.DispatchLink(ctx, dest, link)\n\tif err == nil { d.auditDestination(email, dest) }\n\treturn err')])
p=root/'internal/kernel/xray/dispatcher.go'
with p.open('a',encoding='utf-8',newline='\n') as f:
    f.write('''
func (d *LimitDispatcher) auditDestination(email string, dest net.Destination) {
    d.mu.RLock()
    uid := d.emailToUID[email]
    d.mu.RUnlock()
    if uid > 0 && dest.Address != nil {
        onenewaudit.Record(d.auditNodeID, uid, dest.Address.String())
    }
}
''')
patch('internal/kernel/singbox/singbox.go', [
 ('s.connTracker = NewConnTracker(0)','s.connTracker = NewConnTracker(s.cfg.AuditNodeID)')])
patch('internal/kernel/singbox/conntracker.go', [
 ('"github.com/cedar2025/xboard-node/internal/nlog"','"github.com/cedar2025/xboard-node/internal/nlog"\n\t'+imp),
 ('type ConnTracker struct {','type ConnTracker struct {\n\tauditNodeID int'),
 ('func NewConnTracker(_ int) *ConnTracker {','func NewConnTracker(nodeID int) *ConnTracker {'),
 ('\t\tusers:         make(map[int]*userStats),','\t\tauditNodeID: nodeID,\n\t\tusers:         make(map[int]*userStats),'),
 ('\t// Register connection\n\tif us != nil {','\tt.auditDestination(uid, metadata)\n\t// Register connection\n\tif us != nil {')])
p=root/'internal/kernel/singbox/conntracker.go'
s=p.read_text(encoding='utf-8');a=s.index('func (t *ConnTracker) RoutedPacketConnection(');head,body=s[:a],s[a:]
anchor='\tif us != nil {\n\t\tus.addConn(sourceIP)\n\t}\n\n\tconnID := t.nextID()'
if body.count(anchor)!=1: raise RuntimeError('Unexpected UDP admission anchor')
body=body.replace(anchor,'\tt.auditDestination(uid, metadata)\n'+anchor,1)
s=head+body+'''
func (t *ConnTracker) auditDestination(uid int, md adapter.InboundContext) {
    target := md.Domain
    if target == "" { target = md.Destination.Fqdn }
    onenewaudit.Record(t.auditNodeID, uid, target)
}
'''
p.write_text(s,encoding='utf-8',newline='\n')
dest=root/'internal/onenewaudit';dest.mkdir()
for name in ['agent.go','agent_test.go']: shutil.copyfile(here/name,dest/name)
manifest=root/'build-source.json'
data=json.loads(manifest.read_text(encoding='utf-8'))
data['features']+=['domain-audit-opt-in','onenew-domain-metadata-v1']
data['onenew_audit_overlay_sha256']={name:hashlib.sha256((here/name).read_bytes()).hexdigest() for name in ['agent.go','agent_test.go','patch.py']}
manifest.write_text(json.dumps(data,indent=2)+'\n',encoding='utf-8',newline='\n')
print('Onenew metadata hooks applied; collection controlled by authenticated panel.')
