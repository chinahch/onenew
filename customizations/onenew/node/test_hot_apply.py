import importlib.util
import json
import os
import tempfile
import unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('hot_apply', HERE / 'hot_apply.py')
patcher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(patcher)


class HotApplyTest(unittest.TestCase):
    def test_unpinned_source_is_rejected_before_changes(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory)
            path = source / 'build-source.json'
            path.write_text(json.dumps({'upstream_commit': 'wrong'}), encoding='utf-8')
            before = path.read_bytes()
            with self.assertRaisesRegex(ValueError, 'pinned'):
                patcher.apply(source)
            self.assertEqual(path.read_bytes(), before)

    def test_prepared_source_applies_stale_listener_cleanup_idempotently(self):
        source = Path(os.environ.get('ONENEW_PREPARED_SOURCE', 'prepared'))
        if not (source / 'internal/kernel/singbox/singbox.go').is_file():
            self.skipTest('prepared native source is not available')

        patcher.apply(source)
        first = {
            path.relative_to(source): path.read_bytes()
            for path in source.rglob('*')
            if path.is_file()
        }
        singbox = (source / 'internal/kernel/singbox/singbox.go').read_text(encoding='utf-8')
        self.assertIn('s.hotApplyRemoveStaleInbounds(im, nodeConfig, users, tls)', singbox)
        self.assertIn('func (s *SingBox) hotApplyRemoveStaleInbounds',
                      (source / 'internal/kernel/singbox/onenew_hot_apply_singbox.go').read_text(encoding='utf-8'))
        service = (source / 'internal/service/service.go').read_text(encoding='utf-8')
        self.assertIn('func listenerTopologyChanged(', service)
        self.assertIn('listenerTopologyChanged(s.appliedState.Config, s.lastConfig)', service)
        self.assertIn('s.kernel.Stop()', service)
        self.assertIn('reloadErr', (source / 'internal/service/service_test.go').read_text(encoding='utf-8'))

        patcher.apply(source)
        second = {
            path.relative_to(source): path.read_bytes()
            for path in source.rglob('*')
            if path.is_file()
        }
        self.assertEqual(first, second)


if __name__ == '__main__':
    unittest.main()
