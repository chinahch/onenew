import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('udp_patch', HERE / 'udp-headroom-patch.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class UDPHeadroomPatchTests(unittest.TestCase):
    def fixture(self, root, formatted=False):
        (root / 'build-source.json').write_text(json.dumps({
            'upstream_commit': '0a29338e1f102a462363ce3527417029f89bab28',
            'features': ['onenew-node-bandwidth-v1'],
        }))
        path = root / 'internal/kernel/singbox/conntracker.go'
        path.parent.mkdir(parents=True)
        value = 'return struct{ N.PacketWriter }{c}, nil' if formatted else 'return struct{N.PacketWriter}{c},nil'
        path.write_text('func writer() { ' + value + ' }')
        return path

    def test_upgrades_immutable_bandwidth_baseline_idempotently(self):
        for formatted in [False, True]:
            with self.subTest(formatted=formatted), tempfile.TemporaryDirectory() as temp:
                root = Path(temp)
                path = self.fixture(root, formatted)
                module.patch(root)
                self.assertIn('return newBandwidthPacketWriter(c), nil', path.read_text())
                self.assertEqual((path.parent / 'onenew_udp_headroom.go').read_bytes(), (HERE / 'udp_headroom.go').read_bytes())
                manifest = json.loads((root / 'build-source.json').read_text())
                self.assertIn(module.FEATURE, manifest['features'])
                before = (root / 'build-source.json').read_bytes()
                module.patch(root)
                self.assertEqual(before, (root / 'build-source.json').read_bytes())

    def test_rejects_unknown_source_or_changed_anchor(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            path = self.fixture(root)
            path.write_text('unrecognized writer')
            with self.assertRaisesRegex(ValueError, 'anchor changed'):
                module.patch(root)
            (root / 'build-source.json').write_text('{}')
            with self.assertRaisesRegex(ValueError, 'pinned'):
                module.patch(root)


if __name__ == '__main__':
    unittest.main()
