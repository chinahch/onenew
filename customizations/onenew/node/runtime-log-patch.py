"""Apply pre-sink runtime privacy and bounded machine log delivery to pinned source."""
import argparse,json,shutil,subprocess
from pathlib import Path
HERE=Path(__file__).resolve().parent

def dependency_dir(source):
 # `go list -m .Dir` can be empty on a clean runner; resolving/downloading the
 # named module honors its exact pinned go.mod replace, never latest.
 data=json.loads(subprocess.check_output(['go','mod','download','-json','github.com/sagernet/sing-box'],cwd=source,text=True))
 folder=data.get('Dir')
 if not folder or not Path(folder).is_dir():raise ValueError('pinned sing-box source directory unavailable')
 return Path(folder)

def patch(source):
 source=Path(source)
 def edit(name,old,new):
  p=source/name;s=p.read_text(encoding='utf-8')
  if new in s or "".join(new.split()) in "".join(s.split()):return
  if name=='internal/config/config.go' and 'slog.SetDefault(slog.New(nlog.RuntimeHandler{}))' in new:
   s=s.replace('slog.SetDefault(slog.New(slog.NewTextHandler(nlog.RuntimeWriter{}, &slog.HandlerOptions{Level: slog.LevelDebug})))',old)
  if old not in s:raise ValueError('runtime logging anchor changed: '+name+' '+old[:70])
  p.write_text(s.replace(old,new),encoding='utf-8',newline='\n')
 for p in (HERE/'runtime-log-overlay').rglob('*'):
  if p.is_file():
   dest=source/p.relative_to(HERE/'runtime-log-overlay');dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(p,dest)
 edit('internal/nlog/nlog.go','fullMsg := formatMsg(msg, args)','fullMsg, allowed := runtimeMessage(level, msg, args)\n\tif !allowed { return }\n\tprefix = "runtime"\n\trecordRuntime(level, fullMsg)')
 edit('internal/config/config.go','slog.SetDefault(slog.New(slog.DiscardHandler))','slog.SetDefault(slog.New(nlog.RuntimeHandler{}))')
 edit('internal/config/config.go','fmt.Fprintf(os.Stderr, "failed to create log dir, falling back to stdout: %v\\n", err)','nlog.Core().Error("failed to create log directory", "error", err)')
 edit('internal/config/config.go','fmt.Fprintf(os.Stderr, "failed to open log file, falling back to stdout: %v\\n", err)','nlog.Core().Error("failed to open log file", "error", err)')
 edit('cmd/xboard-node/main.go','fmt.Fprintf(os.Stderr, "failed to load config: %v\\n", err)','nlog.Core().Error("failed to load config", "error", err)')
 edit('cmd/xboard-node/main.go','fmt.Fprintf(os.Stderr, "failed to normalize config: %v\\n", err)','nlog.Core().Error("failed to normalize config", "error", err)')
 edit('internal/machine/machine.go','o.runCtx = ctx','o.runCtx = ctx\n\truntimeCtx, stopRuntimeLogs := context.WithCancel(ctx)\n\tdefer stopRuntimeLogs()\n\tgo o.runRuntimeLogs(runtimeCtx)')
 # Remove the previous checkpoint worker when reapplying to prepared sources.
 machine=source/'internal/machine/machine.go';text=machine.read_text(encoding='utf-8');machine.write_text(text.replace('\n\tgo o.runRuntimeLogs(ctx)',''),encoding='utf-8',newline='\n')
 if (source/'internal/machine/caddy_enrollment_test.go').exists():
  edit('internal/machine/caddy_enrollment_test.go','if r.URL.Path == "/api/v3/server/probes" {','''if r.URL.Path == "/api/v3/server/runtime-logs" {
   var batch struct { MachineID int `json:"machine_id"`; Entries []struct { Seq int64 `json:"seq"` } `json:"entries"` }
   if json.NewDecoder(r.Body).Decode(&batch)!=nil || batch.MachineID!=7 || r.Header.Get("Authorization")!="Bearer fixture-private-machine-token" || r.URL.RawQuery!="" { badAuth.Store(true) }
   last:=int64(0);if len(batch.Entries)>0 {last=batch.Entries[len(batch.Entries)-1].Seq}
   fmt.Fprintf(w, `{"accepted":true,"last_seq":%d}`,last)
   return
  }
  if r.URL.Path == "/api/v3/server/probes" {''')
 edit('internal/cert/cert.go','zap.NewNop()','runtimeCertificateLogger()')
 cert=source/'internal/cert/cert.go';text=cert.read_text(encoding='utf-8')
 if 'zap.' not in text:cert.write_text(text.replace('"go.uber.org/zap"\n',''),encoding='utf-8',newline='\n')
 # sing-box owns its factory; platform callbacks duplicate raw logs and are not
 # a safe sink. Inject our writer before that factory and disable observability
 # of raw messages. The dependency is copied from its immutable pinned module.
 dep=source/'onenew-dependencies/sing-box'
 if not dep.exists():
  folder=dependency_dir(source)
  shutil.copytree(folder,dep)
  for f in dep.rglob('*'):
   if f.is_file():f.chmod(0o644)
 box=dep/'box.go';s=box.read_text(encoding='utf-8')
 if 'OnenewLogWriter' not in s:
  s=s.replace('PlatformLogWriter log.PlatformWriter','PlatformLogWriter log.PlatformWriter\n\tOnenewLogWriter io.Writer')
 s=s.replace('DefaultWriter:  defaultLogWriter,','DefaultWriter:  func() io.Writer { if options.OnenewLogWriter != nil { return options.OnenewLogWriter }; return defaultLogWriter }(),')
 s=s.replace('Observable:     needClashAPI && options.OnenewLogWriter == nil,','Observable:     needClashAPI,')
 box.write_text(s,encoding='utf-8',newline='\n')
 edit('onenew-dependencies/sing-box/log/log.go','logOptions := options.Options','''logOptions := options.Options
 // Onenew routes every configured kernel output through the pre-sink boundary.
 if _, safe := options.DefaultWriter.(interface { Sanitize(string) (string, bool) }); safe { logOptions.Output = "" }''')
 observable=dep/'log/observable.go';s=observable.read_text(encoding='utf-8')
 if 'OnenewSanitizeBoundary' not in s:
  old='nowTime := time.Now()'
  new='''// OnenewSanitizeBoundary: before local writer AND observable subscribers.
 tag := l.tag
 if safe, ok := l.writer.(interface { Sanitize(string) (string, bool) }); ok {
  message, allowed := safe.Sanitize(tag + ": " + F.ToString(args...))
  if !allowed { return }
  args = []any{message}
  tag = ""
 }
 nowTime := time.Now()'''
  if old not in s:raise ValueError('sing-box logging boundary changed')
  s=s.replace(old,new).replace('level, l.tag, F.ToString(args...)','level, tag, F.ToString(args...)')
  observable.write_text(s,encoding='utf-8',newline='\n')
 mod=source/'go.mod';s=mod.read_text(encoding='utf-8');import re
 s=re.sub(r'replace github.com/sagernet/sing-box => .*','replace github.com/sagernet/sing-box => ./onenew-dependencies/sing-box',s);mod.write_text(s,encoding='utf-8',newline='\n')
 edit('internal/kernel/singbox/singbox.go','Context: ctx,','Context: ctx,\n\t\tOnenewLogWriter: nlog.RuntimeWriter{},')
 manifest=source/'build-source.json';data=json.loads(manifest.read_text(encoding='utf-8'));data['features']=sorted(set(data['features']+['onenew-runtime-logs-v1']));manifest.write_text(json.dumps(data,indent=2)+'\n',encoding='utf-8')
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--source',type=Path,required=True);patch(p.parse_args().source.resolve())
