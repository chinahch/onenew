import importlib.util,json,tempfile,unittest
from pathlib import Path
HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('argo_patch',HERE/'argo-patch.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
class ArgoPatchTest(unittest.TestCase):
 def test_overlay_is_required_and_reapplication_rejected(self):
  with tempfile.TemporaryDirectory() as d:
   root=Path(d)
   for name,text in {
    'internal/service/service.go':'package service\ntype Service struct {\n}',
    'internal/service/caddy_certificate_service.go':'func (s *Service) certificateReady(n *model.NodeSpec) bool {\n}',
    'internal/machine/caddy_probes.go':'result = probe.Run(ctx, task)',
    'build-source.json':json.dumps({'features':[]})
   }.items():
    f=root/name;f.parent.mkdir(parents=True,exist_ok=True);f.write_text(text,encoding='utf-8')
   module.apply(root)
   self.assertIn(module.FEATURE,json.loads((root/'build-source.json').read_text())['features'])
   self.assertIn('o.localArgoProbe(task)',(root/'internal/machine/caddy_probes.go').read_text())
   self.assertTrue((root/'internal/service/onenew_argo_test.go').exists())
   with self.assertRaises(ValueError):module.apply(root)
if __name__=='__main__':unittest.main()
