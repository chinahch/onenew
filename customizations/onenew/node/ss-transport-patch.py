"""Enable SS WS/TLS in the existing embedded Xray and machine lifecycle."""
import argparse, shutil, hashlib, json
from pathlib import Path
def apply(source):
    def edit(path, old, new):
        p=source/path;s=p.read_text(encoding='utf-8')
        if s.count(old)!=1: raise ValueError('SS transport anchor changed: '+path)
        p.write_text(s.replace(old,new),encoding='utf-8',newline='\n')
    edit('internal/kernel/xray/config.go','return buildShadowsocks(base, nc, users)',
        'ss := buildShadowsocks(base, nc, users)\n\t\tif nc.Network=="ws" { applyStreamSettings(ss, nc, tc); ss["settings"].(M)["network"]="tcp" }\n\t\treturn ss')
    edit('internal/machine/machine.go','o.startNode(ctx, n) // no-op if already running',
        'if n.Type=="shadowsocks" {\n o.mu.Lock();h:=o.nodes[n.ID];needsSwitch:=h!=nil && h.service!=nil && h.service.ProbeKernelType()!="xray";o.mu.Unlock()\n if needsSwitch {if snapshot,err:=o.client.ForNode(n.ID).GetConfig();err==nil && snapshot!=nil && snapshot.Network=="ws" {o.stopNode(n.ID)}}\n }\n o.startNode(ctx, n) // no-op if already running')
    p=source/'internal/service/onenew_ss_kernel.go'
    p.write_text('package service\nfunc(s *Service)ProbeKernelType()string{return s.cfg.Kernel.Type}\n',encoding='utf-8')
    p=source/'internal/service/onenew_argo.go'
    s=p.read_text(encoding='utf-8')
    s=s.replace('(n.Protocol != "vless" && n.Protocol != "vmess")','(n.Protocol != "vless" && n.Protocol != "vmess" && n.Protocol != "shadowsocks")')
    p.write_text(s,encoding='utf-8',newline='\n')
    shutil.copyfile(Path(__file__).parent/'ss_ws_tls_test.go', source/'internal/kernel/xray/onenew_ss_ws_tls_test.go')
    manifest=source/'build-source.json'
    data=json.loads(manifest.read_text(encoding='utf-8'))
    data['features']=sorted(set(data['features'])|{'onenew-ss-ws-tls-v1'})
    here=Path(__file__).parent
    data['onenew_ss_transport_sha256']={name:hashlib.sha256((here/name).read_bytes()).hexdigest() for name in ['ss-transport-patch.py','ss_ws_tls_test.go']}
    manifest.write_text(json.dumps(data,indent=2)+'\n',encoding='utf-8',newline='\n')
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--source',type=Path,required=True)
    apply(p.parse_args().source)
