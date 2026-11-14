"""Install bounded draft landing exit probes after the existing probe overlays."""
import argparse
from pathlib import Path
import shutil
import hashlib
import json

def patch_enrollment_fixture(source):
    target = source / 'internal/machine/caddy_enrollment_test.go'
    text = target.read_text(encoding='utf-8')
    anchor = '\t\tif r.URL.Path == "/api/v3/server/probes" {'
    if text.count(anchor) != 1 or '/api/v3/server/exit-probes' in text:
        raise ValueError('Exit worker enrollment fixture anchor changed')
    handler = '''\t\tif r.URL.Path == "/api/v3/server/exit-probes" {
            if r.Method != "GET" || r.URL.Query().Get("machine_id") != "7" || r.URL.Query().Get("token") != "" || r.Header.Get("Authorization") != "Bearer fixture-private-machine-token" { badAuth.Store(true) }
            fmt.Fprint(w, `{"tasks":[]}`)
            return
        }
'''
    target.write_text(text.replace(anchor, handler + anchor), encoding='utf-8', newline='\n')

def apply(source):
    target = source / 'internal/machine/machine.go'
    text = target.read_text(encoding='utf-8')
    anchor = '\tprobeCtx, stopProbes := context.WithCancel(ctx)'
    if text.count(anchor) != 1 or 'runExitProbes' in text:
        raise ValueError('Exit worker lifecycle anchor changed')
    patch_enrollment_fixture(source)
    text = text.replace(anchor, '\texitCtx, stopExit := context.WithCancel(ctx)\n\texitDone := make(chan struct{})\n\tgo func() { defer close(exitDone); o.runExitProbes(exitCtx) }()\n\tdefer func() { stopExit(); <-exitDone }()\n' + anchor)
    overlay = Path(__file__).parent / 'exit-probe-overlay'
    for file in overlay.rglob('*.go'):
        out = source / file.relative_to(overlay)
        out.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(file, out)
    target.write_text(text, encoding='utf-8', newline='\n')
    manifest = source / 'build-source.json'
    data = json.loads(manifest.read_text(encoding='utf-8'))
    data['features'] = sorted(set(data.get('features', [])) | {'onenew-exit-probe-v1'})
    data['onenew_exit_probe_overlay_sha256'] = {
        str(file.relative_to(Path(__file__).parent)).replace('\\', '/'): hashlib.sha256(file.read_bytes()).hexdigest()
        for file in [Path(__file__), *sorted(overlay.rglob('*.go'))]
    }
    manifest.write_text(json.dumps(data, indent=2) + '\n', encoding='utf-8', newline='\n')

if __name__ == '__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source',type=Path,required=True)
    apply(parser.parse_args().source.resolve())
