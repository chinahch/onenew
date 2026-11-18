"""Apply pre-sink runtime privacy and bounded machine log delivery to pinned source."""
import argparse,json,shutil,subprocess
from pathlib import Path
HERE=Path(__file__).resolve().parent

def patch(source):
 source=Path(source)
 def edit(name,old,new):
  p=source/name;s=p.read_text(encoding='utf-8')
  if new in s or "".join(new.split()) in "".join(s.split()):return
  if old not in s:raise ValueError('runtime logging anchor changed: '+name+' '+old[:70])
  p.write_text(s.replace(old,new),encoding='utf-8',newline='\n')
 for p in (HERE/'runtime-log-overlay').rglob('*'):
  if p.is_file():
   dest=source/p.relative_to(HERE/'runtime-log-overlay');dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(p,dest)
 edit('internal/nlog/nlog.go','fullMsg := formatMsg(msg, args)','fullMsg, allowed := runtimeMessage(level, msg, args)\n\tif !allowed { return }\n\tprefix = "runtime"\n\trecordRuntime(level, fullMsg)')
 edit('internal/config/config.go','slog.SetDefault(slog.New(slog.DiscardHandler))','slog.SetDefault(slog.New(slog.NewTextHandler(nlog.RuntimeWriter{}, &slog.HandlerOptions{Level: slog.LevelDebug})))')
 edit('internal/config/config.go','fmt.Fprintf(os.Stderr, "failed to create log dir, falling back to stdout: %v\\n", err)','nlog.Core().Error("failed to create log directory", "error", err)')
 edit('internal/config/config.go','fmt.Fprintf(os.Stderr, "failed to open log file, falling back to stdout: %v\\n", err)','nlog.Core().Error("failed to open log file", "error", err)')
 edit('cmd/xboard-node/main.go','fmt.Fprintf(os.Stderr, "failed to load config: %v\\n", err)','nlog.Core().Error("failed to load config", "error", err)')
 edit('cmd/xboard-node/main.go','fmt.Fprintf(os.Stderr, "failed to normalize config: %v\\n", err)','nlog.Core().Error("failed to normalize config", "error", err)')
 edit('internal/machine/machine.go','o.runCtx = ctx','o.runCtx = ctx\n\tgo o.runRuntimeLogs(ctx)')
 # sing-box owns its factory; platform callbacks duplicate raw logs and are not
 # a safe sink. Inject our writer before that factory and disable observability
 # of raw messages. The dependency is copied from its immutable pinned module.
 dep=source/'onenew-dependencies/sing-box'
 if not dep.exists():
  folder=subprocess.check_output(['go','list','-m','-f','{{.Dir}}','github.com/sagernet/sing-box'],cwd=source,text=True).strip()
  shutil.copytree(folder,dep)
  for f in dep.rglob('*'):
   if f.is_file():f.chmod(0o644)
 box=dep/'box.go';s=box.read_text(encoding='utf-8')
 if 'OnenewLogWriter' not in s:
  s=s.replace('PlatformLogWriter log.PlatformWriter','PlatformLogWriter log.PlatformWriter\n\tOnenewLogWriter io.Writer')
 s=s.replace('DefaultWriter:  defaultLogWriter,','DefaultWriter:  func() io.Writer { if options.OnenewLogWriter != nil { return options.OnenewLogWriter }; return defaultLogWriter }(),')
 s=s.replace('Observable:     needClashAPI && options.OnenewLogWriter == nil,','Observable:     needClashAPI,')
 box.write_text(s,encoding='utf-8',newline='\n')
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
