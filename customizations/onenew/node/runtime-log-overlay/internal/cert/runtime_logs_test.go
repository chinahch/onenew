package cert

import (
	"bytes"
	"errors"
	"github.com/cedar2025/xboard-node/internal/nlog"
	"go.uber.org/zap"
	"log/slog"
	"strings"
	"testing"
)

func TestCertificateLoggerRetainsErrorReasonAndDropsFields(t *testing.T) {
	var out bytes.Buffer
	nlog.Init(&out, slog.LevelDebug, false)
	nlog.ResetRuntime()
	runtimeCertificateLogger().Error("certificate renewal failed", zap.Error(errors.New("connection refused")), zap.String("token", "private-sentinel"))
	if !strings.Contains(out.String(), "connection refused") || strings.Contains(out.String(), "private-sentinel") {
		t.Fatal(out.String())
	}
}
