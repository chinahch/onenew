import os,pathlib,shutil,subprocess,tempfile,unittest
HERE=pathlib.Path(__file__).resolve().parents[2]
class CommandTest(unittest.TestCase):
 def execute(self,failed=False):
  token="fixture'$(printf injected)token"
  source=str(HERE/'runtime/app/Onenew/NodeInstallCommand.php').replace('\\','/')
  php=os.environ.get('PHP_BINARY','php')
  expression="require '"+source+"';echo App\\Onenew\\NodeInstallCommand::make('https://panel.example.test',base64_decode('"+__import__('base64').b64encode(token.encode()).decode()+"'),7);"
  command=subprocess.check_output([php,'-r',expression],text=True)
  self.assertIn('bash <(curl -fsSL',command);self.assertNotIn('github.com',command)
  with tempfile.TemporaryDirectory() as tmp:
   root=pathlib.Path(tmp);bin=root/'bin';bin.mkdir()
   (root/'fixture.sh').write_text('#!/bin/bash\nIFS= read -r token\nprintf "%s" "$token" > "$TEST_ROOT/received"\nprintf "%s\\n" "$@" > "$TEST_ROOT/args"\n',newline='\n')
   (bin/'curl').write_text('#!/bin/bash\n[ "$TEST_FAIL" != 1 ] || exit 22\ncat "$TEST_ROOT/fixture.sh"\n',newline='\n');(bin/'curl').chmod(0o755)
   def posix(p):
    p=str(p).replace('\\','/');return '/'+p[0].lower()+p[2:] if os.name=='nt' else p
   env={**os.environ,'TEST_ROOT':str(root).replace('\\','/'),'MOCK_BIN':posix(bin),'TEST_FAIL':str(int(failed))}
   bash='C:/Program Files/Git/bin/bash.exe' if os.name=='nt' else shutil.which('bash')
   p=subprocess.run([bash,'-c','export PATH="$MOCK_BIN:$PATH"; '+command],env=env,capture_output=True,text=True)
   if failed:self.assertNotEqual(p.returncode,0);self.assertFalse((root/'received').exists())
   else:
    self.assertEqual(p.returncode,0,p.stderr);self.assertEqual((root/'received').read_text(),token);self.assertIn('--machine-id\n7',(root/'args').read_text())
 def test_shell_metacharacters_stay_literal_on_stdin(self):self.execute()
 def test_failed_download_exits_without_running_an_installer(self):self.execute(True)
if __name__=='__main__':unittest.main()
