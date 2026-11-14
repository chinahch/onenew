import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

HERE = Path(__file__).parent
spec = importlib.util.spec_from_file_location('exit_probe_patch', HERE / 'exit-probe-patch.py')
patch = importlib.util.module_from_spec(spec)
spec.loader.exec_module(patch)

class ExitProbePatchTest(unittest.TestCase):
    def test_lifecycle_and_manifest_are_installed_and_repeat_is_refused(self):
        with tempfile.TemporaryDirectory() as folder:
            source = Path(folder)
            target = source / 'internal/machine/machine.go'
            target.parent.mkdir(parents=True)
            target.write_text('func run() {\n\tprobeCtx, stopProbes := context.WithCancel(ctx)\n}\n', encoding='utf-8')
            fixture = target.parent / 'caddy_enrollment_test.go'
            fixture.write_text('\t\tif r.URL.Path == "/api/v3/server/probes" {\n}\n', encoding='utf-8')
            (source / 'build-source.json').write_text(json.dumps({'features':['proxy-probes-v1']}), encoding='utf-8')
            patch.apply(source)
            text = target.read_text(encoding='utf-8')
            self.assertIn('defer func() { stopExit(); <-exitDone }()', text)
            fixture_text = fixture.read_text(encoding='utf-8')
            self.assertIn('/api/v3/server/exit-probes', fixture_text)
            self.assertIn('r.Method != "GET"', fixture_text)
            self.assertIn('r.URL.Query().Get("machine_id") != "7"', fixture_text)
            self.assertIn('r.URL.Query().Get("token") != ""', fixture_text)
            self.assertIn('Bearer fixture-private-machine-token', fixture_text)
            self.assertTrue((source / 'internal/exitprobe/exitprobe_test.go').is_file())
            manifest = json.loads((source / 'build-source.json').read_text(encoding='utf-8'))
            self.assertIn('onenew-exit-probe-v1', manifest['features'])
            self.assertIn('exit-probe-patch.py', manifest['onenew_exit_probe_overlay_sha256'])
            with self.assertRaises(ValueError):
                patch.apply(source)

if __name__ == '__main__':
    unittest.main()
