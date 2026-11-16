"""Publish only public shell entrypoints; never copy panel source or credentials."""
import argparse,json,pathlib,subprocess
REPO='repos/chinahch/onenew-releases/'
def payloads(root):
    base=root/'customizations/onenew'
    files={
        'assets/install.sh':(base/'node/install.sh').read_text(encoding='utf-8'),
        'assets/panel-upgrade.sh':(base/'migration/upgrade-restored.sh').read_text(encoding='utf-8'),
        'assets/panel-restore.sh':(base/'migration/restore.sh').read_text(encoding='utf-8')}
    old='BASE="https://github.com/chinahch/onenew/releases/download/$RELEASE"'
    new='BASE="https://get.timi.baby/releases/$RELEASE"'
    if files['assets/install.sh'].count(old)!=1: raise ValueError('Installer download contract changed')
    files['assets/install.sh']=files['assets/install.sh'].replace(old,new)
    for name,text in files.items():
        if not text.startswith('#!/usr/bin/env bash\n'): raise ValueError('Expected standalone Bash script')
        files[name]=text.replace('\r\n','\n')
    return files
def gh_api(path,method='GET',body=None):
    args=['gh','api',path,'--method',method]
    if body is not None: args+=['--input','-']
    result=subprocess.run(args,input=json.dumps(body) if body is not None else None,text=True,encoding='utf-8',capture_output=True)
    if result.returncode: raise RuntimeError('GitHub publication failed; check ONENEW_RELEASE_TOKEN repository access and Contents write permission')
    return json.loads(result.stdout)
def publish(files,api=gh_api):
    head=api(REPO+'git/ref/heads/main')['object']['sha']
    tree=api(REPO+'git/commits/'+head)['tree']['sha']
    updated=api(REPO+'git/trees','POST',{'base_tree':tree,'tree':[
        {'path':name,'mode':'100644','type':'blob','content':text} for name,text in files.items()]})['sha']
    if updated==tree:return
    commit=api(REPO+'git/commits','POST',{'message':'Update public Onenew installation and migration entrypoints','tree':updated,'parents':[head]})['sha']
    api(REPO+'git/refs/heads/main','PATCH',{'sha':commit,'force':False})
if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--root',type=pathlib.Path,required=True);args=parser.parse_args()
    publish(payloads(args.root.resolve()));print('Public shell entrypoints published atomically.')
