"""Include complete source trees and licenses of modules used by release builds."""
import argparse,json,os,shutil,subprocess
from pathlib import Path

def copy_source(src,dst):
    if Path(dst).exists():Path(dst).chmod(0o644)
    return shutil.copy2(src,dst)

def collect(source):
    env={**os.environ,'GOOS':'linux','GOARCH':'amd64','CGO_ENABLED':'0'}
    output=''
    for arch in ['amd64','arm64']:
        output+=subprocess.check_output(['go','list','-mod=readonly','-deps','-tags','with_quic with_utls with_wireguard with_acme with_clash_api','-f','{{if .Module}}{{.Module.Path}}|{{.Module.Version}}|{{.Module.Dir}}{{end}}','./cmd/xboard-node','./cmd/xbctl'],cwd=source,env={**env,'GOARCH':arch},text=True)

    modules={}
    for line in output.splitlines():
        if '|' not in line:continue
        name,version,folder=line.split('|',2)
        if not folder:raise ValueError('Missing source directory for '+name)
        path=Path(folder).resolve()
        if path==source or source in path.parents:continue
        modules[name]=(version,path)
    dest=source/'corresponding-dependencies';dest.mkdir(exist_ok=True)
    manifest=[]
    for name,(version,path) in sorted(modules.items()):
        relative=name+'@'+version
        shutil.copytree(path,dest/relative,dirs_exist_ok=True,ignore=shutil.ignore_patterns('.git'),copy_function=copy_source)
        manifest.append({'module':name,'version':version,'source':'corresponding-dependencies/'+relative})
    (source/'corresponding-dependencies.json').write_text(json.dumps(manifest,indent=2)+'\n',encoding='utf-8')
    print('Included complete dependency source and license trees:',len(manifest))
if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--source',type=Path,required=True);a=p.parse_args();collect(a.source.resolve())
