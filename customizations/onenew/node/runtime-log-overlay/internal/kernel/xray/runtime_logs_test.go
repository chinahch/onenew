package xray

import (
	"bytes"
	"context"
	"github.com/cedar2025/xboard-node/internal/nlog"
	appLog "github.com/xtls/xray-core/app/log"
	xlog "github.com/xtls/xray-core/common/log"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestKernelConsoleAndFileLoggerCannotBypassPrivacy(t *testing.T) {
	for _, kind := range []appLog.LogType{appLog.LogType_Console, appLog.LogType_File} {
		t.Run(kind.String(), func(t *testing.T) {
			var local bytes.Buffer
			nlog.Init(&local, slog.LevelDebug, false)
			nlog.ResetRuntime()
			path := filepath.Join(t.TempDir(), "kernel-raw.log")
			instance, err := appLog.New(context.Background(), &appLog.Config{ErrorLogType: kind, ErrorLogPath: path, ErrorLogLevel: xlog.Severity_Debug, AccessLogType: appLog.LogType_None})
			if err != nil {
				t.Fatal(err)
			}
			defer instance.Close()
			defer xlog.RegisterHandler(runtimeLogHandler{})
			instance.Handle(&xlog.GeneralMessage{Severity: xlog.Severity_Error, Content: "listener started port=45678"})
			instance.Handle(&xlog.GeneralMessage{Severity: xlog.Severity_Error, Content: "listen tcp 192.0.2.8:45678: bind: address already in use password=private-sentinel"})
			for _, bad := range []string{"45678", "192.0.2.8", "private-sentinel", "listener started"} {
				if strings.Contains(local.String(), bad) {
					t.Fatalf("unsafe local log: %s", bad)
				}
			}
			if !strings.Contains(local.String(), "address already in use") {
				t.Fatal(local.String())
			}
			if _, err := os.Stat(path); !os.IsNotExist(err) {
				t.Fatal("raw kernel log file created")
			}
		})
	}
}
