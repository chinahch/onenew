"""Exercise the actual Bash downloader with mock curl, never live credentials."""
import os, pathlib, shutil, subprocess, tempfile, unittest
HERE=pathlib.Path(__file__).resolve().parents[1]
class DownloadTest(unittest.TestCase):
 def test_standalone_scripts_embed_exact_reviewed_downloader(self):
  helper=(HERE/'source-download.sh').read_text()
  for name in ['restore.sh','upgrade-restored.sh']:
   self.assertIn(helper,(HERE/name).read_text())
 def run_case(self, token=None, mode=0o600, failure=False):
  with tempfile.TemporaryDirectory() as tmp:
   root=pathlib.Path(tmp);bin=root/'bin';bin.mkdir();creds=root/'github.token'
   if token is not None: creds.write_text(token);creds.chmod(mode)
   (bin/'curl').write_text('''#!/bin/bash
printf '%s\\n' "$@" > "$TEST_ROOT/args"
dest=;config=;while [ "$#" -gt 0 ]; do case "$1" in -o) dest=$2;shift 2;; --config) config=$2;shift 2;; *) shift;; esac; done
if [ -n "$config" ]; then cp "$config" "$TEST_ROOT/captured-config"; fi
[ "$TEST_FAIL" != 1 ] || exit 22
printf archive > "$dest"
''',newline='\n');(bin/'curl').chmod(0o755)
   (bin/'stat').write_text('#!/bin/bash\ncase "$2" in %a) echo "$TEST_MODE";; %u) id -u;; *) exit 1;; esac\n',newline='\n');(bin/'stat').chmod(0o755)
   bash='C:/Program Files/Git/bin/bash.exe' if os.name=='nt' else shutil.which('bash')
   env={**os.environ,'TEST_ROOT':str(root).replace('\\','/'),'TEST_FAIL':str(int(failure)),'TEST_MODE':oct(mode)[2:],'MOCK_BIN':str(bin).replace('\\','/')}
   if os.name=='nt': env['MOCK_BIN']='/'+env['MOCK_BIN'][0].lower()+env['MOCK_BIN'][2:]
   args=[bash,'-c','export PATH="$MOCK_BIN:$PATH"; source "$1"; onenew_download_source "$2" "$3/out" "$3" "$3/github.token"','test',str(HERE/'source-download.sh').replace('\\','/'),'a'*40,str(root).replace('\\','/')]
   p=subprocess.run(args,env=env,capture_output=True,text=True)
   return p,(root/'args').read_text() if (root/'args').exists() else '',(root/'captured-config').read_text() if (root/'captured-config').exists() else '',list(root.glob('*.curl'))
 def test_public_source_needs_no_credential(self):
  p,args,cfg,left=self.run_case();self.assertEqual(p.returncode,0,p.stderr);self.assertNotIn('--config',args);self.assertEqual(cfg,'')
 def test_saved_token_is_not_in_process_arguments_and_temp_config_is_removed(self):
  p,args,cfg,left=self.run_case('github_pat_fixture_1234567890123456\n');self.assertEqual(p.returncode,0,p.stderr);self.assertNotIn('github_pat_fixture',args);self.assertIn('github_pat_fixture',cfg);self.assertNotIn('github_pat_fixture',p.stdout+p.stderr);self.assertEqual(left,[]);self.assertIn('--disable',args);self.assertIn('https://api.github.com/repos/chinahch/onenew/tarball/',args)
 def test_current_github_installation_jwt_is_accepted_without_fixed_length_assumption(self):
  token='ghs_123_'+('a'*600)+'.base64url-with_dash.signature'
  p,args,cfg,left=self.run_case(token);self.assertEqual(p.returncode,0,p.stderr);self.assertIn(token,cfg);self.assertNotIn(token,args+p.stdout+p.stderr)
 def test_invalid_token_fails_before_network(self):
  p,args,cfg,left=self.run_case('invalid"\nuser=malicious');self.assertNotEqual(p.returncode,0);self.assertEqual(args,'')
 def test_group_readable_credential_is_refused_before_network(self):
  p,args,cfg,left=self.run_case('github_pat_fixture_1234567890123456',mode=0o644);self.assertNotEqual(p.returncode,0);self.assertEqual(args,'')
 def test_failed_authenticated_download_does_not_print_token(self):
  p,args,cfg,left=self.run_case('github_pat_fixture_1234567890123456',failure=True);self.assertNotEqual(p.returncode,0);self.assertNotIn('github_pat_fixture',p.stdout+p.stderr);self.assertEqual(left,[])
if __name__=='__main__':unittest.main()
