import importlib.util,json,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('runtime_patch',HERE/'runtime-log-patch.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
class RuntimePatchTests(unittest.TestCase):
 def test_downloads_exact_named_pinned_replacement_in_clean_cache(self):
  with tempfile.TemporaryDirectory() as temp:
   with patch.object(module.subprocess,'check_output',return_value=json.dumps({'Dir':temp})) as command:
    self.assertEqual(module.dependency_dir(Path(temp)),Path(temp))
    self.assertEqual(command.call_args.args[0],['go','mod','download','-json','github.com/sagernet/sing-box'])
 def test_empty_download_directory_fails_closed(self):
  with patch.object(module.subprocess,'check_output',return_value='{}'):
   with self.assertRaisesRegex(ValueError,'unavailable'):module.dependency_dir(Path('.'))
 def test_every_local_sink_boundary_is_patched(self):
  source=(HERE/'runtime-log-patch.py').read_text(encoding='utf-8')
  for boundary in ['fullMsg := formatMsg','DefaultWriter:','OnenewSanitizeBoundary','slog.SetDefault','failed to load config']:
   self.assertIn(boundary,source)
if __name__=='__main__':unittest.main()
