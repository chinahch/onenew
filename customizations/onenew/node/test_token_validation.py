"""Validate only the installer token gate, without network or service actions."""
import os, shutil, subprocess, unittest
from pathlib import Path
HERE = Path(__file__).resolve().parent
class TokenValidationTest(unittest.TestCase):
    def check(self, token):
        line = next(line for line in (HERE/'install.sh').read_text(encoding='utf-8').splitlines()
                    if "Machine token contains unsupported characters" in line)
        gate = line.split(' || fail ',1)[0]
        bash = 'C:/Program Files/Git/bin/bash.exe' if os.name=='nt' else shutil.which('bash')
        return subprocess.run([bash,'--noprofile','--norc','-c','TOKEN=$1\n'+gate,'token-test',token],
                              capture_output=True,text=True).returncode
    def test_valid_ascii_tokens_and_length_boundaries(self):
        for token in ['fixture0123456789ABCDEF','A'*16,'A'*255,'A'*256,'Az09_+./=~-fixture']:
            with self.subTest(length=len(token)):
                self.assertEqual(self.check(token),0)
    def test_invalid_tokens_are_rejected(self):
        for token in ['', 'A'*15, 'A'*257, 'A'*20+' ', 'A'*20+'\r', 'A'*20+'\n',
                      'A'*20+'"', 'A'*20+';', 'A'*20+'$(id)', 'A'*20+'\\']:
            with self.subTest(length=len(token)):
                self.assertNotEqual(self.check(token),0)
if __name__=='__main__': unittest.main()
