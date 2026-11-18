package singbox

import (
	"bytes"
	"context"
	"github.com/cedar2025/xboard-node/internal/nlog"
	singLog "github.com/sagernet/sing-box/log"
	"github.com/sagernet/sing-box/option"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestKernelLoggerFiltersBeforeWriterAndObservable(t *testing.T) {
	var local bytes.Buffer
	nlog.Init(&local, slog.LevelDebug, false)
	nlog.ResetRuntime()
	factory := singLog.NewDefaultFactory(context.Background(), singLog.Formatter{DisableColors: true}, nlog.RuntimeWriter{}, "", nil, true)
	defer factory.Close()
	factory.SetLevel(singLog.LevelTrace)
	sub, done, err := factory.Subscribe()
	if err != nil {
		t.Fatal(err)
	}
	defer factory.UnSubscribe(sub)
	logger := factory.NewLogger("inbound/vless[45678]")
	logger.Warn("users updated successfully password=private-sentinel")
	logger.Error("listen tcp 192.0.2.8:45678: bind: address already in use token=private-sentinel")
	select {
	case entry := <-sub:
		for _, bad := range []string{"45678", "192.0.2.8", "private-sentinel", "users updated"} {
			if strings.Contains(entry.Message+local.String(), bad) {
				t.Fatalf("unsafe local/observable log %s", bad)
			}
		}
		if !strings.Contains(entry.Message, "address already in use") {
			t.Fatal(entry.Message)
		}
	case <-done:
		t.Fatal("observable closed")
	case <-time.After(time.Second):
		t.Fatal("safe error not delivered")
	}
}

func TestKernelExplicitOutputsCannotBypassSafeWriter(t *testing.T) {
 for _,output:=range []string{filepath.Join(t.TempDir(),"raw-kernel.log"),"stdout","stderr"}{
  var local bytes.Buffer;nlog.Init(&local,slog.LevelDebug,false);nlog.ResetRuntime()
  factory,err:=singLog.New(singLog.Options{Context:context.Background(),Options:option.LogOptions{Output:output,Level:"debug"},DefaultWriter:nlog.RuntimeWriter{},Observable:true})
  if err!=nil{t.Fatal(err)};if err=factory.Start();err!=nil{t.Fatal(err)}
  logger:=factory.NewLogger("inbound/vless[45678]");logger.Warn("users updated successfully");logger.Error("listen tcp 192.0.2.8:45678: bind: address already in use token=private-sentinel");factory.Close()
  if output!="stdout"&&output!="stderr" {if _,err=os.Stat(output);err==nil{t.Fatal("kernel-owned raw log file bypassed safe writer")}}
  if !strings.Contains(local.String(),"address already in use"){t.Fatal("explicit output bypassed safe diagnostic writer")}
  for _,bad:=range []string{"users updated","192.0.2.8","45678","private-sentinel"}{if strings.Contains(local.String(),bad){t.Fatal("explicit output leaked private details")}}
 }
}
