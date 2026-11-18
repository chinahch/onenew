package nlog

import (
	"context"
	"log/slog"
)

// RuntimeHandler keeps structured errors useful while dropping arbitrary attrs.
type RuntimeHandler struct{ attrs []slog.Attr }

func (RuntimeHandler) Enabled(context.Context, slog.Level) bool { return true }
func (h RuntimeHandler) Handle(_ context.Context, r slog.Record) error {
	args := make([]any, 0, 2*(len(h.attrs)+r.NumAttrs()))
	for _, a := range h.attrs {
		args = append(args, a.Key, a.Value.Any())
	}
	r.Attrs(func(a slog.Attr) bool { args = append(args, a.Key, a.Value.Any()); return true })
	logWithColor(r.Level, "runtime", r.Message, args...)
	return nil
}
func (h RuntimeHandler) WithAttrs(attrs []slog.Attr) slog.Handler {
	h.attrs = append(append([]slog.Attr{}, h.attrs...), attrs...)
	return h
}
func (h RuntimeHandler) WithGroup(string) slog.Handler { return h }
