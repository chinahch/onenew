"""Apply staged health to the verified, already prepared v0.1.10 source."""
import argparse,hashlib,json,shutil
from pathlib import Path

def apply(source):
 p=source/'internal/probe/probe.go'
 text=p.read_text(encoding='utf-8')
 if 'HealthStages *HealthStages' in text: raise ValueError('Staged health already applied')
 anchor='type Payload struct {'
 assert text.count(anchor)==1
 text=text.replace(anchor,anchor+'\n HealthStages *HealthStages `json:"health_stages,omitempty"`')
 anchor='type Result struct {'
 text=text.replace(anchor,anchor+'\n Stages *Stages `json:"stages,omitempty"`')
 anchor='return runTask(ctx, t, func(ctx context.Context, p Payload)'
 assert text.count(anchor)==1
 text=text.replace(anchor,'return runStaged(ctx, t, func(ctx context.Context, p Payload)')
 text=text.replace('return "proxy_failed", 0', 'return requestError(ctx, p, e), 0')
 p.write_text(text,encoding='utf-8',newline='\n')
 overlay=Path(__file__).parent/'staged-health-overlay'
 for f in overlay.rglob('*.go'):
  out=source/f.relative_to(overlay);out.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(f,out)
 manifest=source/'build-source.json';data=json.loads(manifest.read_text())
 data['features']=sorted(set(data['features'])|{'onenew-staged-health-v1'})
 data['onenew_staged_health_overlay_sha256']={str(f.relative_to(Path(__file__).parent)).replace('\\','/'):hashlib.sha256(f.read_bytes()).hexdigest() for f in [Path(__file__),*sorted(overlay.rglob('*.go'))]}
 manifest.write_text(json.dumps(data,indent=2)+'\n',encoding='utf-8',newline='\n')
if __name__=='__main__':
 parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--source',type=Path,required=True);apply(parser.parse_args().source.resolve())
