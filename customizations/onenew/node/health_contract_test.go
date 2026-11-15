package probe

import (
 "context"
 "testing"
 "time"
)

// Exercise the production task gate: the request callback must actually run.
func TestOnenewHealthTargetContract(t *testing.T) {
 for _, target := range []string{Target, "https://www.gstatic.com/generate_204", "https://arbitrary.invalid/"} {
  called := false
  task := Task{ID:"health-contract", Lease:"test-lease", Expires:time.Now().Add(time.Minute),
   Payload:Payload{Target:target, Kernel:"singbox"}}
  r := runTask(context.Background(), task,
   func(context.Context, Payload)(string,int64){called=true;return "",0}, nil)
  allowed := target != "https://arbitrary.invalid/"
  if called != allowed || (allowed && r.State != "succeeded") || (!allowed && r.Error != "configuration_unavailable") {
   t.Fatalf("target %q: called=%v state=%s code=%s",target,called,r.State,r.Error)
  }
 }
 if Target != "http://cp.cloudflare.com/generate_204" {t.Fatal("panel/native target mismatch")}
}
