import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('egress_patch', HERE / 'egress-patch.py')
patcher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(patcher)

class EgressPatchTest(unittest.TestCase):
    def test_rejects_unpinned_source(self):
        with tempfile.TemporaryDirectory() as folder:
            source = Path(folder)
            (source / 'build-source.json').write_text(json.dumps({'upstream_commit':'different'}))
            with self.assertRaisesRegex(ValueError, 'pinned'):
                patcher.apply(source)

    def test_applies_once_without_touching_caddy_or_outbounds(self):
        with tempfile.TemporaryDirectory() as folder:
            source = Path(folder)
            files = {
                'internal/panel/types.go':'type NodeConfig struct {\n}',
                'internal/model/types.go':'type NodeSpec struct {\n}',
                'internal/model/panel.go':'\t\tKernelType:          nc.KernelType,\n\t\tKernelType:          n.KernelType,',
                'internal/model/validate.go':'\teffectiveKernelType :=',
                'internal/kernel/xray/config.go':'\tapplyMachineIPFamily(cfg, kcfg)',
                'internal/kernel/singbox/config.go':'\tapplyMachineIPFamily(cfg, kcfg)',
                'cmd/xboard-node/main.go':'runtime caddy',
            }
            for name,text in files.items():
                path=source/name; path.parent.mkdir(parents=True,exist_ok=True); path.write_text(text)
            manifest={'upstream_commit':'0a29338e1f102a462363ce3527417029f89bab28','features':['onenew-hot-apply-v1']}
            (source/'build-source.json').write_text(json.dumps(manifest))
            patcher.apply(source)
            first={p.relative_to(source):p.read_bytes() for p in source.rglob('*') if p.is_file()}
            patcher.apply(source)
            self.assertEqual(first,{p.relative_to(source):p.read_bytes() for p in source.rglob('*') if p.is_file()})
            self.assertEqual((source/'cmd/xboard-node/main.go').read_text(),'runtime caddy')
            self.assertIn('model.PanelIPFamily(kcfg, nc)',(source/'internal/kernel/xray/config.go').read_text())

if __name__ == '__main__': unittest.main()
