package xray

import (
	"github.com/cedar2025/xboard-node/internal/nlog"
	appLog "github.com/xtls/xray-core/app/log"
	xlog "github.com/xtls/xray-core/common/log"
)

type runtimeLogHandler struct{}

func (runtimeLogHandler) Handle(m xlog.Message) { nlog.RuntimeWriter{}.Write([]byte(m.String())) }
func (runtimeLogHandler) Close() error          { return nil }
func init() {
	// Both configured console and configured file outputs pass the same boundary.
	// Never open a second kernel-owned raw log file.
	for _, kind := range []appLog.LogType{appLog.LogType_Console, appLog.LogType_File} {
		appLog.RegisterHandlerCreator(kind, func(appLog.LogType, appLog.HandlerCreatorOptions) (xlog.Handler, error) {
			return runtimeLogHandler{}, nil
		})
	}
	xlog.RegisterHandler(runtimeLogHandler{})
}
