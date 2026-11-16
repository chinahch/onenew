"""Disposable CI deployment only: real authenticated upload -> verify -> stop/apply/restart."""
import json,time,urllib.request,urllib.error,urllib.parse,os,subprocess
assert os.environ.get('ONENEW_WEB_RESTORE_CI')=='isolated-only'
BASE='http://127.0.0.1:7001';auth=None

def request(path,data=None,raw=None,content_type=None,token=True):
    headers={'Accept':'application/json'}
    if token and auth:headers['Authorization']=auth
    if data is not None:raw=json.dumps(data).encode();content_type='application/json'
    if content_type:headers['Content-Type']=content_type
    with urllib.request.urlopen(urllib.request.Request(BASE+path,data=raw,headers=headers),timeout=30) as response:
        body=response.read();return json.loads(body) if 'application/json' in response.headers.get('Content-Type','') else body

def wait(path,terminal,data=None,token=True,limit=150):
    for _ in range(limit):
        try:
            value=request(path,data=data,token=token)
            if value.get('state') in terminal:return value
        except (OSError,ValueError):pass
        time.sleep(1)
    raise AssertionError('Timed out waiting for restore lifecycle')

auth=request('/api/v1/passport/auth/login',{'email':'ci-onenew@example.test','password':'ci-onenew-disposable-2048'},token=False)['data']['auth_data']
print('::add-mask::'+auth)
info=request('/api/onenew/admin/restore')
if not info['supported']:
    diagnostic=r'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); clearstatcache(); echo json_encode(["heartbeat_age"=>is_file("/run/onenew-restore-agent.heartbeat")?time()-filemtime("/run/onenew-restore-agent.heartbeat"):null,"migration_supported"=>app(App\Onenew\Migration\MigrationService::class)->info()["supported"],"redis_local"=>in_array(config("database.redis.default.host"),["127.0.0.1","localhost"],true),"cache_local"=>in_array(config("database.redis.cache.host"),["127.0.0.1","localhost"],true),"redis_url_set"=>(bool)config("database.redis.default.url")]);'
    subprocess.run(['docker','compose','exec','-T','-u','www','onenew','php','-r',diagnostic],check=True)
assert info['supported'],info
password='ci-web-restore-fixture-password'
export=request('/api/onenew/admin/migration/exports',{'password':password,'password_confirmation':password})
ready=wait('/api/onenew/admin/migration/exports/'+export['id'],['ready','failed']);assert ready['state']=='ready'
ticket=request('/api/onenew/admin/migration/exports/'+export['id']+'/ticket',{})['ticket']
package=request('/api/onenew/migration/download',raw=urllib.parse.urlencode({'ticket':ticket}).encode(),content_type='application/x-www-form-urlencoded',token=False)
assert package.startswith(b'ONENEW1\n')

def upload(pwd):
    state=request('/api/onenew/admin/restore',{'filename':'fixture.onm','bytes':len(package)})
    id=state['id'];chunk_bytes=info['chunk_bytes']
    for offset in range(0,len(package),chunk_bytes):
        chunk=package[offset:offset+chunk_bytes];boundary='onenew-ci-'+os.urandom(8).hex()
        body=(f'--{boundary}\r\nContent-Disposition: form-data; name="offset"\r\n\r\n{offset}\r\n--{boundary}\r\nContent-Disposition: form-data; name="chunk"; filename="chunk.bin"\r\nContent-Type: application/octet-stream\r\n\r\n').encode()+chunk+f'\r\n--{boundary}--\r\n'.encode()
        request('/api/onenew/admin/restore/'+id+'/chunk',raw=body,content_type='multipart/form-data; boundary='+boundary)
    request('/api/onenew/admin/restore/'+id+'/verify',{'password':pwd})
    return id,wait('/api/onenew/admin/restore/'+id,['ready','failed'])

bad,failed=upload('wrong-fixture-password');assert failed['state']=='failed'
assert request('/api/onenew/user/me')['user']['email']=='ci-onenew@example.test'
id,verified=upload(password);assert verified['state']=='ready',verified
assert verified['summary']['counts']['users']>0
# A post-backup marker proves the actual database is switched, not just status text.
script=r'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); admin_setting(["web_restore_ci_marker"=>"after-backup"]);'
subprocess.run(['docker','compose','exec','-T','-u','www','onenew','php','-r',script],check=True,stdout=subprocess.DEVNULL)
confirm=request('/api/onenew/admin/restore/'+id+'/confirm',{'confirmed':True})
result=wait('/api/onenew/restore/progress',['success','failed'],{'id':id,'token':confirm['progress_token']},token=False,limit=180)
assert result['state']=='success',result
# Existing browser authentication is discarded; a fresh login uses the restored account.
auth=request('/api/v1/passport/auth/login',{'email':'ci-onenew@example.test','password':'ci-onenew-disposable-2048'},token=False)['data']['auth_data'];print('::add-mask::'+auth)
assert request('/api/onenew/user/me')['user']['email']=='ci-onenew@example.test'
check=r'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if(admin_setting("web_restore_ci_marker")!==null)exit(1);'
subprocess.run(['docker','compose','exec','-T','-u','www','onenew','php','-r',check],check=True,stdout=subprocess.DEVNULL)
print('Real browser-upload protocol, wrong-password rejection, database switch and application restart passed')
