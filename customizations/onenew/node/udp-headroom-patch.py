"""Preserve UDP allocation metadata across the non-bypassable bandwidth writer."""
import argparse
import hashlib
import json
import re
import shutil
from pathlib import Path

HERE = Path(__file__).resolve().parent
FEATURE = 'onenew-udp-headroom-v1'


def patch(source):
    source = Path(source).resolve()
    manifest_path = source / 'build-source.json'
    manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
    if (manifest.get('upstream_commit') != '0a29338e1f102a462363ce3527417029f89bab28'
            or 'onenew-node-bandwidth-v1' not in manifest.get('features', [])):
        raise ValueError('Requires pinned source with node bandwidth overlay')
    path = source / 'internal/kernel/singbox/conntracker.go'
    text = path.read_text(encoding='utf-8')
    # The immutable source baseline already contains the bandwidth patch.
    # Accept its original and gofmt forms, and fail closed if it has drifted.
    old = r'return\s+struct\s*\{\s*N\.PacketWriter\s*\}\s*\{\s*c\s*\}\s*,\s*nil'
    if 'return newBandwidthPacketWriter(c), nil' not in text:
        text, count = re.subn(old, 'return newBandwidthPacketWriter(c), nil', text)
        if count != 1:
            raise ValueError('UDP bandwidth writer anchor changed')
        path.write_text(text, encoding='utf-8', newline='\n')
    for name in ['udp_headroom.go', 'udp_headroom_test.go']:
        shutil.copyfile(HERE / name, source / 'internal/kernel/singbox' / ('onenew_' + name))
    manifest['features'] = sorted(set(manifest['features'] + [FEATURE]))
    manifest['onenew_udp_headroom_sha256'] = {
        name: hashlib.sha256((HERE / name).read_bytes()).hexdigest()
        for name in ['udp-headroom-patch.py', 'udp_headroom.go', 'udp_headroom_test.go']
    }
    manifest_path.write_text(json.dumps(manifest, indent=2) + '\n', encoding='utf-8')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, required=True)
    patch(parser.parse_args().source)
