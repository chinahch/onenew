import importlib.util, io, json, tarfile, tempfile, unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('node_build', HERE / 'build.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)

class BuildTest(unittest.TestCase):
    def test_archive_uses_only_own_executable_names(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / 'caddy').write_bytes(b'fixture-node')
            (root / 'caddyctl').write_bytes(b'fixture-cli')
            data = builder.archive(root, 1700000000)
            with tarfile.open(fileobj=io.BytesIO(data), mode='r:gz') as package:
                self.assertEqual(package.getnames(), ['caddy', 'caddyctl'])
                self.assertEqual(package.extractfile('caddy').read(), b'fixture-node')

    def test_rejects_unmatched_source_before_compilation(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / 'build-source.json').write_text(json.dumps({'upstream_commit': 'wrong', 'features': []}))
            with self.assertRaisesRegex(ValueError, 'pinned'):
                builder.validate_source(root)

    def test_v17_requires_udp_headroom_staged_health_and_listener_lifecycle(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            features = ['landing-v1', 'proxy-probes-v1', 'certificate-lifecycle-v1',
                        'onenew-domain-metadata-v1', 'onenew-hot-apply-v1',
                        'onenew-exit-probe-v1', 'onenew-panel-egress-v1',
                        'onenew-node-bandwidth-v1', 'onenew-argo-offload-v1',
                        'onenew-health-cloudflare-v1', 'onenew-health-https-v1',
                        'onenew-ss-ws-tls-v1', 'onenew-staged-health-v1',
                        'onenew-runtime-logs-v1']
            manifest = {'upstream_commit': builder.UPSTREAM, 'features': features}
            path = root / 'build-source.json'
            path.write_text(json.dumps(manifest))
            with self.assertRaisesRegex(ValueError, 'overlays'):
                builder.validate_source(root)
            features.append('onenew-udp-headroom-v1')
            path.write_text(json.dumps(manifest))
            with self.assertRaisesRegex(ValueError, 'overlays'):
                builder.validate_source(root)
            features.append('onenew-staged-health-v2')
            path.write_text(json.dumps(manifest))
            with self.assertRaisesRegex(ValueError, 'overlays'):
                builder.validate_source(root)
            features.append('onenew-listener-lifecycle-v1')
            path.write_text(json.dumps(manifest))
            self.assertEqual(builder.validate_source(root), manifest)

    def test_version_metadata_matches_installer_and_release_catalog(self):
        meta = json.loads((HERE / 'version.json').read_text(encoding='utf-8'))
        self.assertEqual(builder.VERSION, meta['binary_version'])
        self.assertEqual(meta['binary_version'], 'onenew-v' + meta['version'])
        self.assertEqual(meta['release_tag'], 'node-v' + meta['version'])
        installer = (HERE / 'install.sh').read_text(encoding='utf-8')
        self.assertIn(f"VERSION={meta['binary_version']}\n", installer)
        self.assertIn(f"RELEASE={meta['release_tag']}\n", installer)
        catalog = json.loads((HERE.parent / 'remote-upgrade/app/Onenew/RemoteUpgrade/catalog.json').read_text(encoding='utf-8'))
        self.assertTrue(catalog, 'release catalog must not be empty')
        self.assertTrue(all(k.startswith('v') for k in catalog))

if __name__ == '__main__': unittest.main()
