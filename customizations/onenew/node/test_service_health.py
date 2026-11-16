"""Exercise installer health gating without touching real services."""
import os,re,shutil,subprocess,unittest
from pathlib import Path
HERE=Path(__file__).resolve().parent
class ServiceHealthTest(unittest.TestCase):
    def check(self, service, health='ok', running=True, machine=7, panel='https://panel.example.test'):
        source=(HERE/'install.sh').read_text(encoding='utf-8')
        function=re.search(r'machine_healthy\(\) \{\n.*?\n\s*\}',source,re.S).group(0)
        function=function.replace('/usr/local/bin/caddyctl','fixture_ctl')
        script='''PANEL=https://panel.example.test
MACHINE=7
manager_running(){ return "$FIXTURE_MANAGER_EXIT"; }
fixture_ctl(){ printf 'ID MODE PANEL TARGET SERVICE HEALTH\\nfixture machine %s machine_id=%s %s %s\\n' "$FIXTURE_PANEL" "$FIXTURE_MACHINE" "$FIXTURE_SERVICE" "$FIXTURE_HEALTH"; }
'''+function+'\nmachine_healthy\n'
        env={**os.environ,'FIXTURE_MANAGER_EXIT':'0' if running else '1',
             'FIXTURE_PANEL':panel,'FIXTURE_MACHINE':str(machine),'FIXTURE_SERVICE':service,'FIXTURE_HEALTH':health}
        bash='C:/Program Files/Git/bin/bash.exe' if os.name=='nt' else shutil.which('bash')
        return subprocess.run([bash,'--noprofile','--norc','-c',script],env=env,capture_output=True).returncode
    def test_unknown_cli_service_is_healthy_when_manager_running_and_health_ok(self):
        self.assertEqual(self.check('unknown'),0)
    def test_cli_active_does_not_override_stopped_manager(self):
        self.assertNotEqual(self.check('active',running=False),0)
    def test_binding_and_node_health_are_still_required(self):
        self.assertNotEqual(self.check('unknown',health='failed'),0)
        self.assertNotEqual(self.check('unknown',machine=8),0)
        self.assertNotEqual(self.check('unknown',panel='https://wrong.example.test'),0)
        self.assertEqual(self.check('active'),0)
if __name__=='__main__':unittest.main()
