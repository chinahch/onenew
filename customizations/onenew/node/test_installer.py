"""Offline shell regressions: no writes to /etc and no service changes."""
import hashlib, io, os, shutil, subprocess, tarfile, tempfile, unittest
from pathlib import Path

HERE = Path(__file__).resolve().parent

class InstallerTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        self.package = self.root / 'onenew-node-linux-amd64.tar.gz'
        with tarfile.open(self.package, 'w:gz') as archive:
            for name, content in [('caddy', '#!/bin/sh\necho "caddy onenew-v0.1.3 (built fixture)"\n'), ('caddyctl', '#!/bin/sh\nexit 0\n')]:
                data = content.encode()
                info = tarfile.TarInfo(name)
                info.size, info.mode = len(data), 0o755
                archive.addfile(info, io.BytesIO(data))
        self.sums = self.root / 'SHA256SUMS-amd64'
        self.sums.write_text(hashlib.sha256(self.package.read_bytes()).hexdigest() + '  ' + self.package.name + '\n')
        self.script('uname', '#!/bin/sh\necho x86_64\n')
        self.script('systemctl', '#!/bin/sh\necho SERVICE-MUST-NOT-RUN >&2\nexit 99\n')
        self.script('curl', '''#!/bin/bash
url= dest=
while [ "$#" -gt 0 ]; do case "$1" in -o) dest=$2; shift 2;; https:*) url=$1; shift;; *) shift;; esac; done
cp "$FIXTURE_ROOT/${url##*/}" "$dest"
''')

    def tearDown(self):
        self.tmp.cleanup()

    def script(self, name, body):
        file = self.bin / name
        file.write_text(body, newline='\n')
        file.chmod(0o755)

    def run_installer(self, panel='https://panel.example.test', check_only=True):
        env = {**os.environ, 'PATH': str(self.bin) + os.pathsep + os.environ['PATH'], 'FIXTURE_ROOT': str(self.root).replace('\\', '/')}
        bash = 'C:/Program Files/Git/bin/bash.exe' if os.name == 'nt' else shutil.which('bash')
        env['MOCK_BIN'] = str(self.bin).replace('\\', '/')
        if os.name == 'nt':
            env['MOCK_BIN'] = '/' + env['MOCK_BIN'][0].lower() + env['MOCK_BIN'][2:]
        args = ['--check-only'] if check_only else []
        return subprocess.run([bash, '--noprofile', '--norc', '-c', 'export PATH="$MOCK_BIN:$PATH"; exec bash "$@"', 'test-installer', str(HERE / 'install.sh').replace('\\', '/'), *args, '--mode', 'machine', '--panel', panel, '--token', 'fixture-private-token', '--machine-id', '7'], env=env, capture_output=True, text=True, encoding='utf-8', errors='replace')

    def test_existing_path_caddy_stops_before_any_host_mutation(self):
        self.script('id', '#!/bin/sh\necho 0\n')
        self.script('caddy', '#!/bin/sh\necho existing-web-server\n')
        # Even a regression must not allow this test to create a host directory.
        self.script('mkdir', '#!/bin/sh\necho MUTATION-MUST-NOT-RUN >&2\nexit 99\n')
        result = self.run_installer(check_only=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('caddy', result.stderr)
        self.assertNotIn('MUTATION-MUST-NOT-RUN', result.stderr)
        self.assertNotIn('SERVICE-MUST-NOT-RUN', result.stderr)

    def test_valid_release_checks_without_service_changes_or_secret_output(self):
        result = self.run_installer()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('Verified onenew-v0.1.3', result.stdout)
        self.assertNotIn('fixture-private-token', result.stdout + result.stderr)
        self.assertNotIn('SERVICE-MUST-NOT-RUN', result.stderr)

    def test_bad_checksum_stops_before_executing_binary(self):
        self.sums.write_text('0' * 64 + '  ' + self.package.name + '\n')
        result = self.run_installer()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('checksum mismatch', result.stderr)
        self.assertNotIn('Verified', result.stdout)

    def test_plain_http_and_url_credentials_are_rejected(self):
        for panel in ['http://panel.example.test', 'https://user:password@panel.example.test']:
            result = self.run_installer(panel)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('HTTPS panel', result.stderr)

if __name__ == '__main__': unittest.main()
