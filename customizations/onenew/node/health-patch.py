"""Align panel and node health contracts and validate HTTPS CF Trace responses."""
import argparse, hashlib, json, shutil
from pathlib import Path

def apply(source):
    here = Path(__file__).parent
    file = source / 'internal/probe/probe.go'
    text = file.read_text(encoding='utf-8')
    old = 'const Target = "http://cp.cloudflare.com/generate_204"'
    new = 'const Target = "https://www.cloudflare.com/cdn-cgi/trace"'
    if text.count(old) != 1:
        raise ValueError('Health target contract anchor changed')
    text = text.replace(old, new)
    anchor = 't.Payload.Target != Target ||'
    if text.count(anchor) != 1: raise ValueError('Health task gate anchor changed')
    text = text.replace(anchor, '(t.Payload.Target != Target && t.Payload.Target != "http://cp.cloudflare.com/generate_204") ||')
    anchor = '_, e = io.Copy(io.Discard, io.LimitReader(resp.Body, 1024))'
    if text.count(anchor) != 1: raise ValueError('Health response reader anchor changed')
    text = text.replace(anchor, 'body, readErr := io.ReadAll(io.LimitReader(resp.Body, 4097))\n\te = readErr')
    anchor = 'if resp.StatusCode != 204 {'
    if text.count(anchor) != 1: raise ValueError('Health success check anchor changed')
    text = text.replace(anchor, 'if !validHealthResponse(p.Target, resp.StatusCode, body) {')
    file.write_text(text, encoding='utf-8', newline='\n')
    tests = source/'internal/probe/probe_test.go'
    text = tests.read_text(encoding='utf-8')
    text = text.replace('Target != "http://cp.cloudflare.com/generate_204"', 'Target != "https://www.cloudflare.com/cdn-cgi/trace"')
    text = text.replace('w.WriteHeader(204)', 'w.WriteHeader(200); _, _ = w.Write([]byte("ip=203.0.113.4\\ncolo=LAX\\nloc=US\\n"))')
    tests.write_text(text, encoding='utf-8', newline='\n')
    for name, dest in [
        ('health_contract_test.go','onenew_health_contract_test.go'),
        ('health_response.go','onenew_health_response.go'),
        ('health_response_test.go','onenew_health_response_test.go'),
    ]:
        shutil.copyfile(here/name,source/'internal/probe'/dest)
    manifest = source / 'build-source.json'
    data = json.loads(manifest.read_text(encoding='utf-8'))
    data['features'] = sorted(set(data['features']) | {'onenew-health-cloudflare-v1', 'onenew-health-https-v1'})
    data['onenew_health_sha256'] = {name: hashlib.sha256((here/name).read_bytes()).hexdigest() for name in [
        'health-patch.py','health_contract_test.go','health_response.go','health_response_test.go']}
    manifest.write_text(json.dumps(data, indent=2)+'\n', encoding='utf-8', newline='\n')

if __name__ == '__main__':
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--source', type=Path, required=True)
    apply(p.parse_args().source.resolve())
