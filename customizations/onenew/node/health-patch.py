"""Keep the native health task contract aligned with the panel."""
import argparse, hashlib, json, shutil
from pathlib import Path

def apply(source):
    here = Path(__file__).parent
    file = source / 'internal/probe/probe.go'
    text = file.read_text(encoding='utf-8')
    old = 'const Target = "https://www.gstatic.com/generate_204"'
    new = 'const Target = "http://cp.cloudflare.com/generate_204"'
    if text.count(old) + text.count(new) != 1:
        raise ValueError('Health target contract anchor changed')
    # Accept only the two fixed targets during a rolling panel/node upgrade.
    text = text.replace(old, new).replace(
        't.Payload.Target != Target ||',
        '(t.Payload.Target != Target && t.Payload.Target != "https://www.gstatic.com/generate_204") ||')
    file.write_text(text, encoding='utf-8', newline='\n')
    shutil.copyfile(here / 'health_contract_test.go', source / 'internal/probe/onenew_health_contract_test.go')
    manifest = source / 'build-source.json'
    data = json.loads(manifest.read_text(encoding='utf-8'))
    data['features'] = sorted(set(data['features']) | {'onenew-health-cloudflare-v1'})
    data['onenew_health_sha256'] = {name: hashlib.sha256((here/name).read_bytes()).hexdigest() for name in ['health-patch.py','health_contract_test.go']}
    manifest.write_text(json.dumps(data, indent=2)+'\n', encoding='utf-8', newline='\n')

if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--source', type=Path, required=True)
    apply(p.parse_args().source.resolve())
