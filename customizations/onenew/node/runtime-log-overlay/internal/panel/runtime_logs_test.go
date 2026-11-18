package panel

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/nlog"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestRuntimeActualHTTPUploadMatchesFilteredLocalOutput(t *testing.T) {
	var local bytes.Buffer
	nlog.Init(&local, slog.LevelDebug, false)
	nlog.ResetRuntime()
	nlog.ForNode("vless", 45678).Info("config updated, 3 users")
	nlog.Core().Error("listener failed", "node_id", 987654, "error", "listen tcp 192.0.2.88:45678: bind: address already in use password=forbidden-secret")
	batch := nlog.PendingRuntime()
	posted := false
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v3/server/runtime-logs" || r.Method != "POST" || r.Header.Get("Authorization") != "Bearer fixture-machine" || r.URL.RawQuery != "" {
			t.Error("unsafe request identity")
		}
		raw, _ := io.ReadAll(r.Body)
		for _, forbidden := range []string{"45678", "987654", "192.0.2.88", "forbidden-secret", "fixture-machine", "config updated"} {
			if strings.Contains(string(raw)+local.String(), forbidden) {
				t.Errorf("forbidden value in local/upload: %s", forbidden)
			}
		}
		var in struct {
			MachineID int                 `json:"machine_id"`
			Entries   []nlog.RuntimeEntry `json:"entries"`
		}
		json.Unmarshal(raw, &in)
		if in.MachineID != 7 || len(in.Entries) != 1 || !strings.Contains(in.Entries[0].Message, "address already in use") {
			t.Errorf("incorrect sanitized payload: %s", raw)
		}
		posted = true
		fmt.Fprintf(w, `{"accepted":true,"last_seq":%d}`, batch.Entries[len(batch.Entries)-1].Seq)
	}))
	defer srv.Close()
	c := NewClient(config.PanelConfig{URL: srv.URL, Token: "fixture-machine", MachineID: 7})
	if e := c.SendRuntimeLogs(context.Background(), batch); e != nil || !posted {
		t.Fatal(e)
	}
}
