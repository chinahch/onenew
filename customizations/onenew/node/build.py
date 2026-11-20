"""Package the fixed dual-kernel source using Onenew runtime names."""
import argparse, gzip, hashlib, io, json, os, subprocess, tarfile
from datetime import datetime, timezone
from pathlib import Path

VERSION = 'onenew-v0.1.17'
UPSTREAM = '0a29338e1f102a462363ce3527417029f89bab28'

def validate_source(source):
    manifest = json.loads((source / 'build-source.json').read_text(encoding='utf-8'))
    required = {'landing-v1', 'proxy-probes-v1', 'certificate-lifecycle-v1', 'onenew-domain-metadata-v1', 'onenew-hot-apply-v1', 'onenew-exit-probe-v1', 'onenew-panel-egress-v1', 'onenew-node-bandwidth-v1', 'onenew-argo-offload-v1', 'onenew-health-cloudflare-v1', 'onenew-health-https-v1', 'onenew-ss-ws-tls-v1', 'onenew-staged-health-v1', 'onenew-runtime-logs-v1'}
    required.add('onenew-udp-headroom-v1')
    required.add('onenew-staged-health-v2')
    required.add('onenew-listener-lifecycle-v1')
    if manifest.get('upstream_commit') != UPSTREAM or not required.issubset(manifest.get('features', [])):
        raise ValueError('Requires pinned dual-kernel source and all Onenew overlays')
    return manifest

def brand(source):
    cli = source / 'cmd/xbctl/main.go'
    text = cli.read_text(encoding='utf-8')
    changes = {
        'custom caddy build: install a verified caddy package': 'Onenew build: use a verified Onenew release package',
        'xboard-node status': 'caddy status',
        'https://github.com/cedar2025/xboard-node': 'https://github.com/chinahch/onenew',
    }
    for old, new in changes.items():
        if old not in text and new not in text:
            raise ValueError('CLI branding anchor changed: ' + old)
        text = text.replace(old, new)
    cli.write_text(text, encoding='utf-8', newline='\n')
    for name in ['cmd/xboard-node/main.go', 'internal/config/config.go']:
        file = source / name
        text = file.read_text(encoding='utf-8')
        # Keep module imports, copyright and protocol identifiers unchanged.
        text = text.replace('"xboard-node %s (built %s)', '"caddy %s (built %s)')
        text = text.replace('"/etc/xboard-node"', '"/etc/caddy"')
        file.write_text(text, encoding='utf-8', newline='\n')

def archive(folder, epoch):
    raw = io.BytesIO()
    with tarfile.open(fileobj=raw, mode='w', format=tarfile.PAX_FORMAT) as package:
        for name in ['caddy', 'caddyctl']:
            content = (folder / name).read_bytes()
            info = tarfile.TarInfo(name)
            info.size, info.mtime, info.mode = len(content), epoch, 0o755
            package.addfile(info, io.BytesIO(content))
    output = io.BytesIO()
    with gzip.GzipFile(fileobj=output, filename='', mode='wb', mtime=0) as zipped:
        zipped.write(raw.getvalue())
    return output.getvalue()

def build(source, arch, out):
    manifest = validate_source(source)
    brand(source)
    out.mkdir(parents=True, exist_ok=True)
    folder = out / arch
    folder.mkdir(exist_ok=True)
    epoch = manifest['source_date_epoch']
    stamp = datetime.fromtimestamp(epoch, timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
    env = {**os.environ, 'CGO_ENABLED': '0', 'GOOS': 'linux', 'GOARCH': arch, 'GOAMD64': 'v1'}
    flags = f'-s -w -X main.version={VERSION} -X main.buildTime={stamp}'
    for name, target in [('caddy', './cmd/xboard-node'), ('caddyctl', './cmd/xbctl')]:
        command = ['go', 'build', '-p', '2', '-mod=readonly', '-trimpath', '-buildvcs=false', '-ldflags', flags]
        if name == 'caddy':
            command += ['-tags', 'with_quic with_utls with_wireguard with_acme with_clash_api']
        subprocess.run(command + ['-o', str(folder / name), target], cwd=source, env=env, check=True)
    updater_source = Path(__file__).resolve().parent / 'updater'
    updater_name = f'caddy-updater-linux-{arch}'
    subprocess.run(['go', 'build', '-mod=readonly', '-trimpath', '-buildvcs=false',
                    '-ldflags', '-s -w',
                    '-o', str(out / updater_name), '.'], cwd=updater_source, env=env, check=True)
    (out / f'SHA256SUMS-updater-{arch}').write_text(
        hashlib.sha256((out / updater_name).read_bytes()).hexdigest() + '  ' + updater_name + '\n', encoding='utf-8')
    content = archive(folder, epoch)
    name = f'onenew-node-linux-{arch}.tar.gz'
    (out / name).write_bytes(content)
    (out / f'SHA256SUMS-{arch}').write_text(hashlib.sha256(content).hexdigest() + '  ' + name + '\n', encoding='utf-8')
    (out / f'build-info-{arch}.json').write_text(json.dumps({**manifest, 'version': VERSION, 'architecture': arch, 'runtime': 'caddy', 'CGO_ENABLED': 0}, indent=2) + '\n', encoding='utf-8')

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, required=True)
    parser.add_argument('--arch', choices=['amd64', 'arm64'], required=True)
    parser.add_argument('--out', type=Path, required=True)
    args = parser.parse_args()
    build(args.source.resolve(), args.arch, args.out.resolve())
