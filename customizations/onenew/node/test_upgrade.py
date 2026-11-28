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
        self.installed.joinpath('caddyctl').write_text('#!/bin/sh\nif [ "$1" = instance ]; then printf "ID MODE PANEL TARGET SERVICE HEALTH\\nfixture machine https://panel.example.test machine_id=7 active ok\\n"; else echo old-cli; fi\n')
        for file in self.installed.iterdir(): file.chmod(0o755)
        self.original = {str(p): p.read_bytes() for p in [*self.native.iterdir(), *self.installed.iterdir(), self.system/'caddy.service']}
        self.script('id', '#!/bin/sh\necho 0\n')
        self.script('sleep', '#!/bin/sh\nexit 0\n')
        if os.name == 'nt': self.script('flock', '#!/bin/sh\nexit 0\n')
        self.script('systemctl', '''#!/bin/bash
echo "$1" >> "$FIXTURE_ROOT/service-actions"
if [ "$1" = restart ] && [ "$2" = caddy-updater.service ]; then
 flock -n "$FIXTURE_ROOT/etc/caddy/updater.lock" true || exit 75
fi
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
            for name, content in [('caddy', '#!/bin/sh\necho "caddy onenew-v0.1.20 (built candidate)"\n'), ('caddyctl', cli)]:
                data = content.encode(); info = tarfile.TarInfo(name); info.size = len(data); info.mode = 0o755
                archive.addfile(info, io.BytesIO(data))
        self.sums.write_text(hashlib.sha256(self.package.read_bytes()).hexdigest() + '  ' + self.package.name + '\n')
        # Replace literal host paths only in this disposable test copy.
        script = (HERE / 'install.sh').read_text()
        for path in ['/etc/caddy', '/etc/systemd', '/lib/systemd', '/usr/lib/systemd', '/usr/local/bin', '/etc/init.d', '/etc/php-fpm', '/etc/xboard-node']:
            script = script.replace(path, self.posix(self.root) + path)
        self.fixture_script = self.root / 'install-fixture.sh'
        self.fixture_script.write_text(script, newline='\n')

    def refresh(self, journal=False, fail_restart=False, background=False, openrc=False):
        helper = self.installed / 'caddy-updater'
        helper.write_text('#!/bin/sh\n# previous assistant\n')
        helper.chmod(0o755)
        (self.system / 'caddy-updater.service').write_text('previous-assistant-unit')
        updater = self.root / 'caddy-updater-linux-amd64'
        updater.write_text('#!/bin/sh\ncase "$5" in panel) echo https://panel.example.test;; machine) echo 7;; *) exit 1;; esac\n')
        (self.root / 'SHA256SUMS-updater-amd64').write_text(hashlib.sha256(updater.read_bytes()).hexdigest() + '  ' + updater.name + '\n')
        script = self.bin.joinpath('systemctl').read_text().replace('echo "$1" >>', 'echo "$1 $2" >>')
        script = script.replace('if [ "$1" = show ]; then', '''if [ "$4" = caddy-updater.service ] && [ "$1" = show ]; then
 case "$2" in
 --property=DropInPaths) [ ! -e "$FIXTURE_ROOT/lxc-dropin" ] || echo /run/systemd/system/service.d/zzz-lxc-service.conf;;
 --property=Description) echo 'Onenew Native Upgrade Assistant';;
 --property=FragmentPath) echo "$FIXTURE_ROOT/etc/systemd/system/caddy-updater.service";;
 --property=ExecStart) echo "{ path=$FIXTURE_ROOT/usr/local/bin/caddy-updater ; argv[]=$FIXTURE_ROOT/usr/local/bin/caddy-updater run --root $FIXTURE_ROOT/etc/caddy --manager systemd ; ignore_errors=no ; }";;
 esac
 exit 0
fi
if [ "$1" = show ]; then''')
        if fail_restart:
            script = script.replace('if [ "$1" = show ]; then', 'if [ "$1" = restart ] && [ ! -e "$FIXTURE_ROOT/restart-failed" ]; then touch "$FIXTURE_ROOT/restart-failed"; exit 1; fi\nif [ "$1" = show ]; then')
        self.script('systemctl', script)
        if openrc:
            self._prepare_openrc()
            (self.system / 'caddy-updater.service').unlink()
            unit = self.root / 'etc/init.d/caddy-updater'
            unit.write_text('#!/sbin/openrc-run\ndescription="Onenew Native Upgrade Assistant"\ncommand="' + self.posix(self.installed) + '/caddy-updater"\ncommand_args="run --root ' + self.posix(self.native) + ' --manager openrc"\nsupervisor="supervise-daemon"\nrespawn_delay=10\nrespawn_max=0\noutput_log="/var/log/caddy-updater.log"\nerror_log="/var/log/caddy-updater.err"\ndepend() { need net; }\n')
            unit.chmod(0o755)
            self.script('rc-service', '#!/bin/sh\necho "$2 $1" >> "$FIXTURE_ROOT/service-actions"\nif [ "$1" = caddy-updater ] && [ "$2" = restart ]; then flock -n "$FIXTURE_ROOT/etc/caddy/updater.lock" true || exit 75; fi\nexit 0\n')
        if journal:
            (self.native / 'updater').mkdir()
            (self.native / 'updater/journal.json').write_text('{"state":"running","secret":"hidden"}')
        env = {**os.environ, 'FIXTURE_ROOT': self.posix(self.root), 'MOCK_BIN': self.posix(self.bin)}
        bash = 'C:/Program Files/Git/bin/bash.exe' if os.name == 'nt' else shutil.which('bash')
        args = [bash, '--noprofile', '--norc', '-c', 'export PATH="$MOCK_BIN:$PATH"; exec bash "$@"', 'refresh', self.posix(self.fixture_script), '--refresh-assistant']
        if background:
            return subprocess.Popen(args, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True), args, env
        return subprocess.run(args, env=env, capture_output=True, text=True)

    def test_refresh_only_replaces_assistant_without_token_or_core_mutation(self):
        self.package.unlink()
        result = self.refresh()
        self.assertEqual(result.returncode, 0, result.stderr)
        for path, content in self.original.items(): self.assertEqual(Path(path).read_bytes(), content)
        actions = (self.root / 'service-actions').read_text()
        self.assertIn('stop caddy-updater.service', actions)
        self.assertNotIn('stop caddy.service', actions)
        self.assertIn('case "$5"', (self.installed / 'caddy-updater').read_text())

    def test_refresh_installer_guard_spans_stop_replace_and_restart(self):
        self.script('flock', '#!/bin/sh\necho "flock $*" >> "$FIXTURE_ROOT/service-actions"\nexit 0\n')
        result = self.refresh()
        self.assertEqual(result.returncode, 0, result.stderr)
        actions = (self.root / 'service-actions').read_text()
        self.assertLess(actions.index('flock -n 8'), actions.index('stop caddy-updater.service'))
        self.assertLess(actions.index('flock -u 9'), actions.index('restart caddy-updater.service'))
        self.assertLess(actions.index('restart caddy-updater.service'), actions.index('flock -u 8'))

    @unittest.skipIf(os.name == 'nt', 'Linux concurrent flock integration')
    def test_concurrent_refresh_cannot_enter_stop_or_restore_window(self):
        import time
        service = self.bin / 'systemctl'
        script = service.read_text().replace('if [ "$1" = show ]; then', 'if [ "$1" = stop ] && [ "$2" = caddy-updater.service ]; then\n touch "$FIXTURE_ROOT/stopped-gate"\n while [ ! -e "$FIXTURE_ROOT/release-gate" ]; do /bin/sleep .02; done\nfi\nif [ "$1" = show ]; then')
        self.script('systemctl', script)
        first, args, env = self.refresh(background=True)
        try:
            deadline = time.monotonic() + 10
            while not (self.root / 'stopped-gate').exists() and time.monotonic() < deadline:
                time.sleep(.02)
            self.assertTrue((self.root / 'stopped-gate').exists())
            second = subprocess.run(args, env=env, capture_output=True, text=True, timeout=5)
            self.assertNotEqual(second.returncode, 0)
            self.assertIn('holds the installer lock', second.stderr)
            self.assertEqual((self.root / 'service-actions').read_text().count('stop caddy-updater.service'), 1)
        finally:
            (self.root / 'release-gate').touch()
            stdout, stderr = first.communicate(timeout=10)
        self.assertEqual(first.returncode, 0, stderr)

    def test_openrc_refresh_preserves_core_configuration_and_credentials(self):
        result = self.refresh(openrc=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        for path, content in self.original.items():
            if path != str(self.system / 'caddy.service'):
                self.assertEqual(Path(path).read_bytes(), content)
        actions = (self.root / 'service-actions').read_text()
        self.assertIn('stop caddy-updater', actions)
        self.assertNotIn('stop caddy\n', actions)
        self.assertIn('restart caddy-updater', actions)
        self.assertIn('respawn_max=0', (self.root / 'etc/init.d/caddy-updater').read_text())

    def test_refresh_accepts_known_lxc_global_dropin(self):
        (self.root / 'lxc-dropin').touch()
        result = self.refresh()
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_refresh_bad_checksum_never_stops_assistant(self):
        self.script('sha256sum', '#!/bin/sh\nexit 1\n')
        result = self.refresh()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Assistant checksum mismatch', result.stderr)
        self.assertNotIn('stop ', (self.root / 'service-actions').read_text())

    def test_refresh_contended_lock_restarts_old_assistant_without_mutation(self):
        self.script('flock', '#!/bin/sh\n[ "$1" = -u ] || [ "$2" = 8 ]\n')
        result = self.refresh()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('holds the upgrade lock', result.stderr)
        self.assertIn('previous assistant', (self.installed / 'caddy-updater').read_text())
        self.assertIn('restart caddy-updater.service', (self.root / 'service-actions').read_text())

    def test_refresh_preserves_existing_journal_without_stopping_assistant(self):
        result = self.refresh(journal=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('resolve the task first', result.stderr)
        self.assertNotIn('hidden', result.stderr)
        self.assertTrue((self.native / 'updater/journal.json').exists())
        self.assertIn('previous assistant', (self.installed / 'caddy-updater').read_text())
        self.assertNotIn('stop caddy-updater.service', (self.root / 'service-actions').read_text())

    def test_refresh_restores_previous_helper_and_service_after_start_failure(self):
        result = self.refresh(fail_restart=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('previous assistant', (self.installed / 'caddy-updater').read_text())
        self.assertEqual((self.system / 'caddy-updater.service').read_text(), 'previous-assistant-unit')
        self.assertGreaterEqual((self.root / 'service-actions').read_text().count('restart caddy-updater.service'), 2)

    @staticmethod
    def posix(path):
        value = str(path).replace('\\', '/')
        return '/' + value[0].lower() + value[2:] if os.name == 'nt' else value

    def upgrade(self, machine='7', token='fixture-private-token'):
        env = {**os.environ, 'FIXTURE_ROOT': self.posix(self.root), 'MOCK_BIN': self.posix(self.bin), 'FIXTURE_TOKEN': token}
        bash = 'C:/Program Files/Git/bin/bash.exe' if os.name == 'nt' else shutil.which('bash')
        return subprocess.run([bash, '--noprofile', '--norc', '-c', 'export PATH="$MOCK_BIN:$PATH"; printf "%s\\n" "$FIXTURE_TOKEN" | bash "$@"', 'upgrade-test', self.posix(self.fixture_script), '--upgrade', '--panel', 'https://panel.example.test', '--token-stdin', '--machine-id', machine], env=env, input=token + ('\n' if os.name != 'nt' else ''), capture_output=True, text=True, encoding='utf-8', errors='replace')

    @unittest.skipIf(os.name == 'nt', 'Linux flock integration')
    def test_manual_upgrade_refuses_assistant_shared_lock_before_mutation(self):
        import fcntl
        with (self.native / 'updater.lock').open('w') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = self.upgrade()
            self.assertNotEqual(result.returncode, 0)
            self.assertIn('holds the upgrade lock', result.stderr)
            actions = self.root / 'service-actions'
            self.assertNotIn('stop', actions.read_text() if actions.exists() else '')
            for path, content in self.original.items(): self.assertEqual(Path(path).read_bytes(), content)

    def test_bare_upgrade_reuses_saved_binding_and_credentials(self):
        updater = self.root / 'caddy-updater-linux-amd64'
        updater.write_text('#!/bin/sh\ncase "$5" in panel) echo https://panel.example.test;; machine) echo 7;; token) echo fixture-private-token;; *) exit 1;; esac\n')
        (self.root / 'SHA256SUMS-updater-amd64').write_text(hashlib.sha256(updater.read_bytes()).hexdigest() + '  ' + updater.name + '\n')
        env = {**os.environ, 'FIXTURE_ROOT': self.posix(self.root), 'MOCK_BIN': self.posix(self.bin)}
        bash = 'C:/Program Files/Git/bin/bash.exe' if os.name == 'nt' else shutil.which('bash')
        result = subprocess.run([bash, '--noprofile', '--norc', '-c', 'export PATH="$MOCK_BIN:$PATH"; exec bash "$@"', 'bare-upgrade', self.posix(self.fixture_script), '--upgrade'], env=env, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn('fixture-private-token', result.stdout + result.stderr)
        self.assertTrue((self.installed / 'caddy-updater').exists())
        self.assertEqual((self.native / 'config.yml').read_bytes(), self.original[str(self.native / 'config.yml')])

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
        self.assertIn('Previous version verified healthy', result.stderr)
        self.assertEqual(list(self.installed.glob('.onenew-rollback.*')), [])
        for path, content in self.original.items(): self.assertEqual(Path(path).read_bytes(), content)
        self.assertNotIn('fixture-private-token', result.stdout + result.stderr)

    def test_panel_rejects_stale_token_before_service_stop(self):
        result = self.upgrade(token='old-or-incorrect-token')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('authentication rejected (HTTP 403)', result.stderr)
        actions = self.root/'service-actions'
        self.assertNotIn('stop', actions.read_text() if actions.exists() else '')
        for path, content in self.original.items():
            self.assertEqual(Path(path).read_bytes(), content)

    def test_panel_403_with_correct_looking_token_never_stops_service(self):
        (self.root / 'panel403').touch()
        result = self.upgrade()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('HTTP 403', result.stderr)
        actions = self.root/'service-actions'
        self.assertNotIn('stop', actions.read_text() if actions.exists() else '')

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
        self.script('rc-update', '#!/bin/sh\nexit 0\n')
        self.script('supervise-daemon', '#!/bin/sh\nexit 0\n')
        init = self.root / 'etc/init.d/caddy'
        init.parent.mkdir(parents=True, exist_ok=True)
        description = 'Onenew Native Node' if valid else 'Foreign Service'
        init.write_text('#!/sbin/openrc-run\ndescription="' + description + '"\ncommand="' + self.posix(self.installed) + '/caddy"\ncommand_args="-c ' + self.posix(self.native) + '/config.yml"\nsupervisor="supervise-daemon"\n')
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

    def test_openrc_unknown_cli_service_is_accepted_when_openrc_and_health_are_ok(self):
        self._prepare_openrc()
        with tarfile.open(self.package, 'r:gz') as archive:
            entries = [(item.name, archive.extractfile(item).read()) for item in archive.getmembers()]
        with tarfile.open(self.package, 'w:gz') as archive:
            for name, data in entries:
                if name == 'caddyctl': data = data.replace(b' active ok', b' unknown ok')
                item = tarfile.TarInfo(name); item.mode=0o755; item.size=len(data)
                archive.addfile(item, io.BytesIO(data))
        self.sums.write_text(hashlib.sha256(self.package.read_bytes()).hexdigest()+'  '+self.package.name+'\n')
        result=self.upgrade()
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertIn('candidate',(self.installed/'caddy').read_text())

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
