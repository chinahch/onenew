package cert

import (
	"fmt"
	"github.com/cedar2025/xboard-node/internal/nlog"
	"go.uber.org/zap"
	"go.uber.org/zap/zapcore"
	"log/slog"
)

type runtimeCertificateCore struct{ fields []zapcore.Field }

func (runtimeCertificateCore) Enabled(zapcore.Level) bool { return true }
func (c runtimeCertificateCore) With(fields []zapcore.Field) zapcore.Core {
	c.fields = append(append([]zapcore.Field{}, c.fields...), fields...)
	return c
}
func (c runtimeCertificateCore) Check(e zapcore.Entry, ce *zapcore.CheckedEntry) *zapcore.CheckedEntry {
	return ce.AddCore(e, c)
}
func (c runtimeCertificateCore) Write(e zapcore.Entry, fields []zapcore.Field) error {
	args := []any{}
	for _, f := range append(append([]zapcore.Field{}, c.fields...), fields...) {
		if f.Key == "error" || f.Key == "reason" {
			value := f.String
			if f.Interface != nil {
				value = fmt.Sprint(f.Interface)
			}
			args = append(args, f.Key, value)
		}
	}
	logger := nlog.Core()
	switch {
	case e.Level >= zapcore.ErrorLevel:
		logger.Error(e.Message, args...)
	case e.Level == zapcore.WarnLevel:
		logger.Warn(e.Message, args...)
	case e.Level == zapcore.DebugLevel:
		logger.Debug(e.Message, args...)
	default:
		logger.Info(e.Message, args...)
	}
	_ = slog.LevelInfo
	return nil
}
func (runtimeCertificateCore) Sync() error  { return nil }
func runtimeCertificateLogger() *zap.Logger { return zap.New(runtimeCertificateCore{}) }
