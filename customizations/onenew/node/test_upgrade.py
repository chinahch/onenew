"""Upgrade exercises run against an isolated filesystem and fake service manager."""
import hashlib
import io
import os
import shutil
import subprocess
import tarfile
import unittest
from pathlib import Path
from test_installer import InstallerTest, HERE


class UpgradeTest(InstallerTest):
    def setUp(self):
        super().setUp()
        self.native = self.root / 'etc/caddy'
        self.native.mkdir(parents=True)
        self.native.joinpath('config.yml').write_text('panel: https://panel.example.test\nmachine_id: 7\n# keep routes and certificates\n')
        self.native.joinpath('credentials.env').write_text('PRIVATE=old-token\n')
        self.native.joinpath('cert.pem').write_text('private-cert-fixture')
        self.system = self.root / 'etc/systemd/system'
        self.system.mkdir(parents=True)
        self.system.joinpath('caddy.service').write_text('native-unit-do-not-rewrite')
        self.installed = self.root / 'usr/local/bin'
        self.installed.mkdir(parents=True)
        self.installed.joinpath('caddy').write_text('#!/bin/sh\necho "caddy onenew-v0.1.0 (built old)"\n')
        self.installed.joinpath('caddyctl').write_text('#!/bin/sh\necho old-cli\n')
        for file in self.installed.iterdir(): file.chmod(0o755)
        self.original = {str(p): p.read_bytes() for p in [*self.native.iterdir(), *self.installed.iterdir(), self.system/'caddy.service']}
        self.script('id', '#!/bin/sh\necho 0\n')
        self.script('sleep', '#!/bin/sh\nexit 0\n')
        self.script('systemctl', '''#!/bin/bash
echo "$1" >> "$FIXTURE_ROOT/service-actions"
if [ "$1" = show ]; then
 case "$2" in
 --property=Description) echo 'Onenew Native Node';;
 --property=FragmentPath) echo "$FIXTURE_ROOT/etc/systemd/system/caddy.service";;
 --property=WorkingDirectory) echo "$FIXTURE_ROOT/etc/caddy";;
 --property=ExecStart) echo "{ path=$FIXTURE_ROOT/usr/local/bin/caddy ; argv[]=$FIXTURE_ROOT/usr/local/bin/caddy -c $FIXTURE_ROOT/etc/caddy/config.yml ; ignore_errors=no ; }";;
 --property=EnvironmentFiles) echo "$FIXTURE_ROOT/etc/caddy/credentials.env (ignore_errors=no)";;
 --property=DropInPaths)
   if [ -e "$FIXTURE_ROOT/dropin" ]; then echo /bad.conf
   elif [ -e "$FIXTURE_ROOT/lxc-dropin" ]; then echo /run/systemd/system/service.d/zzz-lxc-service.conf
   fi;;
 esac
fi
exit 0
''')
        cli = '''#!/bin/bash
if [ "$1" = machine-token ]; then
 shift
 while [ "$#" -gt 0 ]; do case "$1" in --config) cfg=$2;; --credentials) creds=$2;; --panel-url) panel=$2;; --machine-id) machine=$2;; esac; shift 2; done
 grep -q "panel: $panel" "$cfg" && grep -q "machine_id: $machine" "$cfg" || exit 8
 printf 'PRIVATE=%s\\n' "$CADDY_MACHINE_TOKEN" > "$creds"
elif [ "$1" = instance ]; then
 [ ! -e "$FIXTURE_ROOT/unhealthy" ] || exit 9
 printf 'ID MODE PANEL TARGET SERVICE HEALTH\\nfixture machine https://panel.example.test machine_id=7 active ok\\n'
else exit 10
fi
'''
        with tarfile.open(self.package, 'w:gz') as archive:
            for name, content in [('caddy', '#!/bin/sh\necho "caddy onenew-v0.1.6 (built candidate)"\n'), ('caddyctl', cli)]:
                data = content.encode(); info = tarfile.TarInfo(name); info.size = len(data); info.mode = 0o755
                archive.addfile(info, io.BytesIO(data))
        self.sums.write_text(hashlib.sha256(self.package.read_bytes()).hexdigest() + '  ' + self.package.name + '\n')
        # Replace literal host paths only in this disposable test copy.
        script = (HERE / 'install.sh').read_text()
        for path in ['/etc/caddy', '/etc/systemd', '/lib/systemd', '/usr/lib/systemd', '/usr/local/bin', '/etc/init.d', '/etc/php-fpm', '/etc/xboard-node']:
            script = script.replace(path, self.posix(self.root) + path)
        self.fixture_script = self.root / 'install-fixture.sh'
        self.fixture_script.write_text(script, newline='\n')

    @staticmethod
    def posix(path):
        value = str(path).replace('\\', '/')
        return '/' + value[0].lower() + value[2:] if os.name == 'nt' else value

    def upgrade(self, machine='7'):
        env = {**os.environ, 'FIXTURE_ROOT': self.posix(self.root), 'MOCK_BIN': self.posix(self.bin)}
        bash = 'C:/Program Files/Git/bin/bash.exe' if os.name == 'nt' else shutil.which('bash')
        return subprocess.run([bash, '--noprofile', '--norc', '-c', 'export PATH="$MOCK_BIN:$PATH"; exec bash "$@"', 'upgrade-test', self.posix(self.fixture_script), '--upgrade', '--panel', 'https://panel.example.test', '--token', 'fixture-private-token', '--machine-id', machine], env=env, capture_output=True, text=True, encoding='utf-8', errors='replace')

    def test_upgrade_preserves_configuration_certificate_and_unit(self):
        result = self.upgrade()
        self.assertEqual(result.returncode, 0, result.stderr)
        for file in [self.native/'config.yml', self.native/'cert.pem', self.system/'caddy.service']:
            self.assertEqual(file.read_bytes(), self.original[str(file)])
        self.assertIn('candidate', self.installed.joinpath('caddy').read_text())
        self.assertIn('fixture-private-token', self.native.joinpath('credentials.env').read_text())
        self.assertNotIn('fixture-private-token', result.stdout + result.stderr)
        self.assertEqual(list(self.root.glob('etc/caddy.upgrade-backup.*')), [])
        self.assertEqual(list(self.installed.glob('.onenew-rollback.*')), [])

    def test_cleans_recognized_legacy_backups_only(self):
        legacy = self.root/'etc/caddy.upgrade-backup.fixture'
        (legacy/'config').mkdir(parents=True)
        (legacy/'caddy').write_text('old')
        (legacy/'caddyctl').write_text('old')
        (legacy/'config/credentials.env').write_text('private')
        unrelated = self.root/'etc/user-backup'
        unrelated.mkdir()
        result = self.upgrade()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(legacy.exists())
        self.assertTrue(unrelated.exists())

    def test_failed_health_rolls_back_binaries_and_credentials(self):
        self.root.joinpath('unhealthy').touch()
        result = self.upgrade()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('restored', result.stderr)
        self.assertEqual(list(self.installed.glob('.onenew-rollback.*')), [])
        for path, content in self.original.items(): self.assertEqual(Path(path).read_bytes(), content)
        self.assertNotIn('fixture-private-token', result.stdout + result.stderr)

    def test_wrong_binding_and_dropin_refuse_before_stop(self):
        for machine in ['8', '7']:
            if machine == '7': self.root.joinpath('dropin').touch()
            result = self.upgrade(machine)
            self.assertNotEqual(result.returncode, 0)
            actions = self.root/'service-actions'
            self.assertNotIn('stop', actions.read_text() if actions.exists() else '')
            for path, content in self.original.items(): self.assertEqual(Path(path).read_bytes(), content)

    def test_lxc_global_dropin_does_not_block_upgrade(self):
        self.root.joinpath('lxc-dropin').touch()
        result = self.upgrade()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('candidate', self.installed.joinpath('caddy').read_text())

    def _prepare_openrc(self, valid=True):
        self.script('rc-service', '#!/bin/sh\necho "$2" >> "$FIXTURE_ROOT/service-actions"\nexit 0\n')
        self.script('supervise-daemon', '#!/bin/sh\nexit 0\n')
        init = self.root / 'etc/init.d/caddy'
        init.parent.mkdir(parents=True, exist_ok=True)
        description = 'Onenew Native Node' if valid else 'Foreign Service'
        init.write_text('description="' + description + '"\ncommand="/usr/local/bin/caddy"\ncommand_args="-c /etc/caddy/config.yml"\nsupervisor="supervise-daemon"\n')
        init.chmod(0o755)
        (self.system/'caddy.service').unlink()

    def test_openrc_upgrade_succeeds_without_persistent_backups(self):
        self._prepare_openrc()
        original_cert = (self.native/'cert.pem').read_bytes()
        result = self.upgrade()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('candidate', (self.installed/'caddy').read_text())
        self.assertEqual((self.native/'cert.pem').read_bytes(), original_cert)
        self.assertEqual(list(self.installed.glob('.onenew-rollback.*')), [])
        self.assertEqual(list(self.root.glob('etc/caddy.upgrade-backup.*')), [])

    def test_openrc_refuses_foreign_service_before_mutation(self):
        self._prepare_openrc(valid=False)
        result = self.upgrade()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Unknown OpenRC', result.stderr)
        actions = self.root/'service-actions'
        self.assertNotIn('stop', actions.read_text() if actions.exists() else '')

    def test_web_caddy_binary_is_rejected_before_stop(self):
        self.installed.joinpath('caddy').write_text('#!/bin/sh\necho v2.9.1\n')
        result = self.upgrade()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('not an Onenew', result.stderr)
        self.assertNotIn('stop', self.root.joinpath('service-actions').read_text())


if __name__ == '__main__': unittest.main()

