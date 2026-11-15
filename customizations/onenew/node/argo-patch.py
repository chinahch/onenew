"""Restore upstream TLS-offload semantics without weakening certificate failures."""
import argparse, hashlib, json, shutil
from pathlib import Path

FEATURE = 'onenew-argo-offload-v1'

def apply(source):
    here = Path(__file__).parent
    def replace(path, old, new):
        file = source / path
        text = file.read_text(encoding='utf-8')
        if text.count(old) != 1:
            raise ValueError('Argo patch anchor changed: ' + path)
        file.write_text(text.replace(old, new), encoding='utf-8', newline='\n')
    replace('internal/service/service.go', 'type Service struct {', 'type Service struct {\n\tplainProbePort atomic.Int64')
    replace('internal/service/caddy_certificate_service.go',
        'func (s *Service) certificateReady(n *model.NodeSpec) bool {',
        'func (s *Service) certificateReady(n *model.NodeSpec) bool {\n\ts.plainProbePort.Store(0)\n\tif s.argoPlaintext(n) { s.plainProbePort.Store(int64(n.ServerPort)); return true }')
    replace('internal/machine/caddy_probes.go', 'result = probe.Run(ctx, task)', 'result = probe.Run(ctx, o.localArgoProbe(task))')
    files = [('argo_service.go','internal/service/onenew_argo.go'), ('argo_service_test.go','internal/service/onenew_argo_test.go'),
             ('argo_machine.go','internal/machine/onenew_argo.go'), ('argo_machine_test.go','internal/machine/onenew_argo_test.go')]
    for src, dst in files: shutil.copyfile(here / src, source / dst)
    manifest = source / 'build-source.json'
    data = json.loads(manifest.read_text(encoding='utf-8'))
    data['features'] = sorted(set(data['features']) | {FEATURE})
    data['onenew_argo_sha256'] = {name:hashlib.sha256((here/name).read_bytes()).hexdigest() for name in ['argo-patch.py', *[src for src,_ in files]]}
    manifest.write_text(json.dumps(data, indent=2)+'\n', encoding='utf-8', newline='\n')

if __name__ == '__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('--source',type=Path,required=True)
    apply(p.parse_args().source.resolve())
