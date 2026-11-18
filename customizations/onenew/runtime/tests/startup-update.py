"""Run the real startup wrapper with child PHP fixtures, without Laravel or Redis."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

php = sys.argv[1] if len(sys.argv) > 1 else 'php'
wrapper = Path(__file__).resolve().parents[2] / 'startup-update.php'
assert wrapper.is_file(), 'Startup update wrapper is missing'
with tempfile.TemporaryDirectory(prefix='onenew startup ') as directory:
    root = Path(directory)
    bundle = root / 'bundle with spaces'; bundle.mkdir()
    panel = root / 'panel with spaces'; panel.mkdir()
    shutil.copyfile(wrapper, bundle / wrapper.name)
    fixture = '''<?php
$stage=basename(__FILE__)==='artisan'?($argv[1]==='migrate'?'migrate':'update'):'seed';
$row=['stage'=>$stage,'argv'=>$argv,'cache'=>getenv('CACHE_DRIVER'),'queue'=>getenv('QUEUE_CONNECTION'),'session'=>getenv('SESSION_DRIVER'),'marker'=>getenv('FIXTURE_MARKER')];
file_put_contents(getenv('FIXTURE_LOG'),json_encode($row)."\\n",FILE_APPEND);
exit((int)getenv(strtoupper($stage).'_EXIT'));
'''
    (bundle / 'seed-plugins.php').write_text(fixture)
    (panel / 'artisan').write_text(fixture)
    (panel / '.env').write_text('CACHE_DRIVER=redis\nDO_NOT_SOURCE=$(must-not-execute)\n')
    log = root / 'calls.jsonl'
    env = os.environ.copy()
    env.update(CACHE_DRIVER='array', QUEUE_CONNECTION='sync', SESSION_DRIVER='array', FIXTURE_MARKER='literal $() ; value', FIXTURE_LOG=str(log))
    for seed_exit, migrate_exit, update_exit, expected_stages, expected_code in [(0,0,0,['seed','migrate','update'],0),(23,0,0,['seed'],23),(0,19,0,['seed','migrate'],19),(0,0,37,['seed','migrate','update'],37)]:
        log.unlink(missing_ok=True)
        env.update(SEED_EXIT=str(seed_exit), MIGRATE_EXIT=str(migrate_exit), UPDATE_EXIT=str(update_exit))
        process = subprocess.run([php, str(bundle / wrapper.name), str(panel)], env=env, capture_output=True, text=True)
        assert process.returncode == expected_code, (process.returncode, process.stderr)
        rows = [json.loads(line) for line in log.read_text().splitlines()]
        assert [row['stage'] for row in rows] == expected_stages, rows
        for row in rows:
            assert (row['cache'], row['queue'], row['session']) == ('array','sync','array'), row
            assert row['marker'] == 'literal $() ; value', row
        assert rows[0]['argv'][1:] == [str(panel)], rows
        if len(rows) >= 2:
            assert rows[1]['argv'][1:] == ['migrate','--force','--no-interaction'], rows
        if len(rows) == 3:
            assert rows[2]['argv'][1:] == ['onenew:update','--no-interaction'], rows
    # Reachable-Redis branch inherits its original drivers too; wrapper never globally forces fallback.
    log.unlink()
    env.update(CACHE_DRIVER='redis', QUEUE_CONNECTION='redis', SESSION_DRIVER='redis', SEED_EXIT='0', MIGRATE_EXIT='0', UPDATE_EXIT='0')
    subprocess.run([php, str(bundle / wrapper.name), str(panel)], env=env, check=True)
    assert all(json.loads(line)['cache'] == 'redis' for line in log.read_text().splitlines())
print('Startup wrapper subprocess environment, ordering, arguments and failure propagation passed')
