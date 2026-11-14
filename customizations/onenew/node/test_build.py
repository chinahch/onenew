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

if __name__ == '__main__': unittest.main()
