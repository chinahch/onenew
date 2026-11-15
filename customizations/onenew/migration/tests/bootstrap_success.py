"""Exercise the entire bootstrap with a real controlling TTY and mocked external services."""
import os, sys, pathlib, tempfile, subprocess, time
if os.name != 'posix':
    print('Bootstrap PTY success test runs on Linux CI'); sys.exit(0)
import pty, select
script=pathlib.Path(__file__).resolve().parents[1]/'restore.sh'
with tempfile.TemporaryDirectory(prefix='onenew-bootstrap-') as folder:
    root=pathlib.Path(folder); source=root/'source'; data=root/'original'; bin_dir=root/'bin'
    (source/'customizations/onenew/migration/core').mkdir(parents=True); bin_dir.mkdir()
    (source/'Dockerfile').write_text('FROM scratch\n'); (source/'customizations/onenew/migration/core/restore.php').write_text('<?php\n')
    (source/'compose.onenew.sample.yaml').write_text('name: onenew\nservices:\n  onenew:\n    image: onenew:local\n')
    (data/'data/database').mkdir(parents=True); (data/'plugins/demo').mkdir(parents=True)
    (data/'.env').write_text('APP_KEY=original\nDO_NOT_SOURCE=$(touch '+str(root/'executed')+')\n')
    (data/'data/database/database.sqlite').write_text('database fixture'); (data/'plugins/demo/config.json').write_text('{}')
    archive=root/'source.tar.gz'; subprocess.run(['tar','-czf',str(archive),'-C',str(root),'source'],check=True)
    package=root/'package.onm';package.write_bytes(b'encrypted fixture')
    (bin_dir/'curl').write_text('#!/bin/bash\nwhile (($#));do if [[ "$1" == -o ]];then cp "$TEST_ARCHIVE" "$2";exit;fi;shift;done\nexit 1\n')
    (bin_dir/'docker').write_text('''#!/bin/bash
printf '%s\n' "$*" >> "$TEST_LOG"
if [[ "$1 $2" == 'image inspect' ]];then exit 1;fi
if [[ "$1" == run ]];then
  for arg in "$@";do
    if [[ "$arg" == *target=/restore ]];then destination="${arg#type=bind,source=}";destination="${destination%,target=/restore}";fi
  done
  cat >/dev/null
  mkdir "$destination/restored"
  cp -a "$TEST_DATA/." "$destination/restored/"
fi
exit 0
''')
    for file in bin_dir.iterdir():file.chmod(0o700)
    env=os.environ.copy();env.update(PATH=str(bin_dir)+':'+env['PATH'],TEST_ARCHIVE=str(archive),TEST_DATA=str(data),TEST_LOG=str(root/'docker.log'),COMPOSE_PROJECT_NAME='existing-deployment',ONENEW_REVISION='wrong-inherited-version')
    pid,fd=pty.fork()
    if pid==0:os.execvpe('bash',['bash',str(script),'--revision','a'*40,'--package',str(package),'--target',str(root/'new')],env)
    output=b'';sent=False;status=None;deadline=time.time()+20
    try:
        while time.time()<deadline:
            ready,_,_=select.select([fd],[],[],0.1)
            if ready:
                try:chunk=os.read(fd,65536)
                except OSError:chunk=b''
                output+=chunk
                if not sent and b'Migration password:' in output:os.write(fd,b'private-test-password\n');sent=True
            done,value=os.waitpid(pid,os.WNOHANG)
            if done:status=value;break
        if status is None:os.kill(pid,9);os.waitpid(pid,0);raise AssertionError('Bootstrap timed out')
    finally:os.close(fd)
    assert os.waitstatus_to_exitcode(status)==0,output.decode(errors='replace')
    assert sent and b'private-test-password' not in output
    assert not (root/'executed').exists()
    assert (root/'new/.env').read_bytes()==(data/'.env').read_bytes()
    compose=(root/'new/compose.yaml').read_text();assert 'name: onenew-restored-' in compose and 'image: onenew-restored-' in compose
    log=(root/'docker.log').read_text();assert 'up -d --build --wait' in log and 'onenew:migration-assets' in log
    for line in log.splitlines():
        if ' up ' in line or ' exec ' in line:assert 'compose --project-name onenew-restored-' in line and 'existing-deployment' not in line
    assert not list(root.glob('.onenew-restore.*'))
print('Full bootstrap TTY, isolated project, preserved files and no secret argv passed')
