import importlib.util,json,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('runtime_patch',HERE/'runtime-log-patch.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
class RuntimePatchTests(unittest.TestCase):
 def test_complete_patch_applies_to_clean_source_and_observable_dependency(self):
  with tempfile.TemporaryDirectory() as temp:
   root=Path(temp)
   files={
    'internal/nlog/nlog.go':'fullMsg := formatMsg(msg, args)',
    'internal/config/config.go':'slog.SetDefault(slog.New(slog.DiscardHandler))\nfmt.Fprintf(os.Stderr, "failed to create log dir, falling back to stdout: %v\\n", err)\nfmt.Fprintf(os.Stderr, "failed to open log file, falling back to stdout: %v\\n", err)',
    'cmd/xboard-node/main.go':'fmt.Fprintf(os.Stderr, "failed to load config: %v\\n", err)\nfmt.Fprintf(os.Stderr, "failed to normalize config: %v\\n", err)',
    'internal/machine/machine.go':'o.runCtx = ctx',
    'internal/cert/cert.go':'"go.uber.org/zap"\nzap.NewNop()',
    'internal/kernel/singbox/singbox.go':'Context: ctx,',
    'onenew-dependencies/sing-box/box.go':'PlatformLogWriter log.PlatformWriter\nDefaultWriter:  defaultLogWriter,',
    'onenew-dependencies/sing-box/log/observable.go':'nowTime := time.Now()\nlevel, l.tag, F.ToString(args...)',
    'go.mod':'replace github.com/sagernet/sing-box => pinned-module v1.0.0',
    'build-source.json':json.dumps({'features':[]}),
   }
   for name,text in files.items():p=root/name;p.parent.mkdir(parents=True,exist_ok=True);p.write_text(text,encoding='utf-8')
   module.patch(root)
   self.assertNotIn('"go.uber.org/zap"',(root/'internal/cert/cert.go').read_text())
   self.assertIn('OnenewSanitizeBoundary',(root/'onenew-dependencies/sing-box/log/observable.go').read_text())
   self.assertIn('onenew-runtime-logs-v1',json.loads((root/'build-source.json').read_text())['features'])
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
