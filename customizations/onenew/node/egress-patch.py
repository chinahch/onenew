"""Apply panel egress preference support to the pinned Onenew native source.

Run after hot_apply.py; source preparation only, never changes caddy naming.
"""
import argparse
import json
import re
from pathlib import Path

FEATURE = 'onenew-panel-egress-v1'

def replace_once(source, name, old, new):
    path = source / name
    text = path.read_text(encoding='utf-8')
    # gofmt may align struct fields between preparation and a repeated apply.
    if new in text or re.sub(r'\s+', '', new) in re.sub(r'\s+', '', text):
        return
    if text.count(old) != 1:
        raise ValueError(f'Pinned egress anchor changed: {name}: {old!r}')
    path.write_text(text.replace(old, new), encoding='utf-8', newline='\n')

def apply(source):
    manifest_path = source / 'build-source.json'
    manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
    if manifest.get('upstream_commit') != '0a29338e1f102a462363ce3527417029f89bab28':
        raise ValueError('Requires pinned Onenew native source')
    if 'onenew-hot-apply-v1' not in manifest.get('features', []):
        raise ValueError('Apply hot_apply.py before egress-patch.py')
    here = Path(__file__).resolve().parent
    replace_once(source, 'internal/panel/types.go', 'type NodeConfig struct {', 'type NodeConfig struct {\n\tIPFamily string `json:"ip_family,omitempty"`')
    replace_once(source, 'internal/model/types.go', 'type NodeSpec struct {', 'type NodeSpec struct {\n\tIPFamily string')
    replace_once(source, 'internal/model/panel.go', '\t\tKernelType:          nc.KernelType,', '\t\tIPFamily: nc.IPFamily,\n\t\tKernelType:          nc.KernelType,')
    replace_once(source, 'internal/model/panel.go', '\t\tKernelType:          n.KernelType,', '\t\tIPFamily: n.IPFamily,\n\t\tKernelType:          n.KernelType,')
    replace_once(source, 'internal/model/validate.go', '\teffectiveKernelType :=', '\tswitch n.IPFamily {\n\tcase "", "default", "prefer_ipv4", "prefer_ipv6":\n\tdefault: return fmt.Errorf("invalid panel ip_family %q", n.IPFamily)\n\t}\n\n\teffectiveKernelType :=')
    for backend in ['singbox', 'xray']:
        replace_once(source, f'internal/kernel/{backend}/config.go', '\tapplyMachineIPFamily(cfg, kcfg)', '\tapplyMachineIPFamily(cfg, model.PanelIPFamily(kcfg, nc))')
    for name in ['egress_model.go', 'egress_model_test.go']:
        (source / 'internal/model' / ('onenew_' + name)).write_bytes((here / name).read_bytes())
    for backend in ['singbox', 'xray']:
        test = here / f'egress_{backend}_test.go'
        if test.exists():
            (source / f'internal/kernel/{backend}/onenew_egress_test.go').write_bytes(test.read_bytes())
    features = manifest.setdefault('features', [])
    if FEATURE not in features:
        features.append(FEATURE)
    manifest_path.write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, required=True)
    apply(parser.parse_args().source.resolve())
