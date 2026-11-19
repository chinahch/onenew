package nlog

// Runtime diagnostics are filtered before formatting, local output, and queueing.
import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"log/slog"
	"regexp"
	"strings"
	"sync"
	"time"
)

type RuntimeEntry struct {
	Seq     int64  `json:"seq"`
	Level   string `json:"level"`
	At      int64  `json:"at"`
	Message string `json:"message"`
}
type RuntimeBatch struct {
	DeploymentID string         `json:"deployment_id"`
	BatchID      string         `json:"batch_id"`
	FirstSeq     int64          `json:"first_seq"`
	Entries      []RuntimeEntry `json:"entries"`
	Dropped      int64          `json:"dropped"`
	Version      string         `json:"version"`
}

var runtimeState = struct {
	sync.Mutex
	deployment string
	seq        int64
	queue      []RuntimeEntry
	bytes      int
	dropped    int64
	pending    *RuntimeBatch
}{deployment: runtimeUUID()}

func runtimeUUID() string {
	var b [16]byte
	if _, e := rand.Read(b[:]); e != nil {
		panic("runtime identity entropy unavailable")
	}
	b[6] = (b[6] & 15) | 64
	b[8] = (b[8] & 63) | 128
	s := hex.EncodeToString(b[:])
	return s[:8] + "-" + s[8:12] + "-" + s[12:16] + "-" + s[16:20] + "-" + s[20:]
}

var runtimeHeader = regexp.MustCompile(`(?i)^\s*(?:[-+0-9:.TZ /]+\s+)?\[?(?:TRACE|DEBUG|INFO|WARN(?:ING)?|ERROR|FATAL|PANIC)\]?\s*`)
var runtimeANSI = regexp.MustCompile(`\x1b\[[0-9;]*m`)
var runtimePrivate = regexp.MustCompile(`(?i)(?:bearer\s+\S+|(?:password|passwd|token|secret|authorization|private[_ -]?key|uuid|email|user[_-]?id|node[_-]?id|machine[_-]?id|port|address|host|server|config|payload|body)\s*[:=]\s*(?:"[^"]*"|'[^']*'|\S+))`)
var runtimeURL = regexp.MustCompile(`(?i)\b(?:https?|socks5?|trojan|vless|vmess|ss)://\S+`)
var runtimeAddress = regexp.MustCompile(`(?:\[[0-9a-fA-F:]+\](?::[0-9]+)?|\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}(?::[0-9]+)?|\b[0-9a-fA-F]{8}-[0-9a-fA-F-]{27,}\b|\b[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)+(?::[0-9]+)?|\b[0-9a-fA-F]{0,4}:[0-9a-fA-F:]+:[0-9a-fA-F:]+\b)`)
var runtimeNumbers = regexp.MustCompile(`[0-9]+`)
var runtimeOpaque = regexp.MustCompile(`\b[A-Za-z0-9_+/=-]{25,}\b`)
var runtimeQuoted = regexp.MustCompile(`"[^"\n]*"|'[^'\n]*'`)
var runtimeSuccess = regexp.MustCompile(`(?i)(config|configuration|user|node|listener|inbound|outbound|report pushed|hot.?reload|sync\.|discovered|hot.swapp|limits updated|kernel restart)`)
var runtimeFailure = regexp.MustCompile(`(?i)(error|fail|refused|denied|timeout|timed out|unavailable|unable|invalid|expired|already in use|not found|cannot|could not|panic|warning|warn)`)

func SanitizeRuntime(message string) string {
	message = runtimeANSI.ReplaceAllString(message, "")
	message = regexp.MustCompile(`(?s)-----BEGIN.*?-----END[^\n]*`).ReplaceAllString(message, "[redacted]")
	message = regexp.MustCompile(`\{.*\}`).ReplaceAllString(message, "[redacted]")
	message = runtimePrivate.ReplaceAllString(message, "[redacted]")
	message = runtimeURL.ReplaceAllString(message, "[redacted]")
	message = runtimeQuoted.ReplaceAllString(message, "[redacted]")
	message = runtimeAddress.ReplaceAllString(message, "[redacted]")
	message = runtimeOpaque.ReplaceAllString(message, "[redacted]")
	message = runtimeNumbers.ReplaceAllString(message, "[redacted]")
	message = strings.Join(strings.Fields(message), " ")
	if len(message) > 2048 {
		message = message[:2048] + " [truncated]"
	}
	return message
}
func runtimeMessage(level slog.Level, msg string, args []any) (string, bool) {
	failure := runtimeFailure.MatchString(msg)
	configuration := runtimeSuccess.MatchString(msg)
	for i := 0; i+1 < len(args); i += 2 {
		key := strings.ToLower(fmt.Sprint(args[i]))
		if runtimeSuccess.MatchString(key) || key == "port" || key == "ports" || key == "server_port" {
			configuration = true
		}
		if key == "error" && args[i+1] != nil || key == "reason" && runtimeFailure.MatchString(fmt.Sprint(args[i+1])) {
			failure = true
		}
	}
	if !failure && configuration {
		return "", false
	}
	var b strings.Builder
	b.WriteString(msg)
	// Arbitrary structured fields may contain configurations or identifiers. Only
	// error/reason text is diagnostic, and it is scrubbed by the same boundary.
	for i := 0; i+1 < len(args); i += 2 {
		key := strings.ToLower(fmt.Sprint(args[i]))
		if key == "error" || key == "reason" {
			b.WriteString(": ")
			b.WriteString(fmt.Sprint(args[i+1]))
		}
	}
	text := SanitizeRuntime(b.String())
	return text, text != ""
}
func recordRuntime(level slog.Level, message string) {
	runtimeState.Lock()
	defer runtimeState.Unlock()
	entry := RuntimeEntry{Seq: runtimeState.seq + 1, Level: strings.ToLower(level.String()), At: time.Now().Unix(), Message: message}
	raw, _ := json.Marshal(entry)
	// The pending batch and fresh queue together remain bounded. Nothing is ever
	// persisted as a raw log spool. Saturation is explicit in the next batch.
	n := len(runtimeState.queue)
	size := runtimeState.bytes
	if runtimeState.pending != nil {
		n += len(runtimeState.pending.Entries)
		for _, e := range runtimeState.pending.Entries {
			size += len(e.Message) + 128
		}
	}
	if n >= 1000 || size+len(raw) > 220<<10 {
		runtimeState.dropped++
		return
	}
	runtimeState.seq++
	runtimeState.queue = append(runtimeState.queue, entry)
	runtimeState.bytes += len(raw)
}
func PendingRuntime() RuntimeBatch {
	runtimeState.Lock()
	defer runtimeState.Unlock()
	if runtimeState.pending == nil {
		first := runtimeState.seq + 1
		if len(runtimeState.queue) > 0 {
			first = runtimeState.queue[0].Seq
		}
		runtimeState.pending = &RuntimeBatch{DeploymentID: runtimeState.deployment, BatchID: runtimeUUID(), FirstSeq: first, Entries: runtimeState.queue, Dropped: runtimeState.dropped, Version: "v0.1.15"}
		if runtimeState.pending.Entries == nil {
			runtimeState.pending.Entries = []RuntimeEntry{}
		}
		runtimeState.queue = nil
		runtimeState.bytes = 0
		runtimeState.dropped = 0
	}
	b := *runtimeState.pending
	b.Entries = append([]RuntimeEntry{}, b.Entries...)
	return b
}
func AcknowledgeRuntime(id string) {
	runtimeState.Lock()
	defer runtimeState.Unlock()
	if runtimeState.pending != nil && runtimeState.pending.BatchID == id {
		runtimeState.pending = nil
	}
}

// RuntimeWriter handles complete library messages (including multiline writes)
// without buffering untrusted partial streams or retaining raw content.
type RuntimeWriter struct{}

func (RuntimeWriter) Write(p []byte) (int, error) {
	for _, line := range strings.Split(string(p), "\n") {
		if strings.TrimSpace(line) == "" {
			continue
		}
		level := slog.LevelInfo
		lower := strings.ToLower(line)
		if strings.Contains(lower, "error") || strings.Contains(lower, "fatal") || strings.Contains(lower, "panic") {
			level = slog.LevelError
		} else if strings.Contains(lower, "warn") {
			level = slog.LevelWarn
		}
		line = runtimeHeader.ReplaceAllString(runtimeANSI.ReplaceAllString(line, ""), "")
		logWithColor(level, "runtime", line)
	}
	return len(p), nil
}
func ResetRuntime() {
	runtimeState.Lock()
	defer runtimeState.Unlock()
	runtimeState.deployment = runtimeUUID()
	runtimeState.seq = 0
	runtimeState.queue = nil
	runtimeState.bytes = 0
	runtimeState.dropped = 0
	runtimeState.pending = nil
}

// Sanitize is also used by the embedded kernel factory before its observable
// feed, so in-process subscribers never receive unfiltered messages.
func (RuntimeWriter) Sanitize(message string) (string, bool) {
	message = runtimeHeader.ReplaceAllString(runtimeANSI.ReplaceAllString(message, ""), "")
	level := slog.LevelInfo
	if runtimeFailure.MatchString(message) {
		level = slog.LevelWarn
	}
	return runtimeMessage(level, message, nil)
}
