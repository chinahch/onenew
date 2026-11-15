package limiter
import("context";"testing";"time";"sync";"golang.org/x/time/rate")
func TestNodeBandwidthSharesTokensAcrossUsersAndKeepsDirectionsIndependent(t *testing.T){
 b:=NewNodeBandwidth();b.SetRate(8);b.Up.AllowN(time.Now(),b.Up.Burst())
 started:=time.Now();var wg sync.WaitGroup
 for i:=0;i<2;i++ {wg.Add(1);go func(){defer wg.Done();if err:=b.Wait(context.Background(),true,128*1024);err!=nil{t.Error(err)}}()};wg.Wait()
 if time.Since(started)<180*time.Millisecond{t.Fatal("independent connection buckets bypass aggregate limit")}
 if !b.Down.AllowN(time.Now(),32*1024){t.Fatal("upload consumed download capacity")}
 other:=NewNodeBandwidth();other.SetRate(8);if other.Up==b.Up{t.Fatal("nodes share capacity")}
 old:=b.Up;b.SetRate(4);if b.Up!=old||b.Up.Limit()!=500000{t.Fatal("hot update lost shared bucket")};b.SetRate(0);if old.Limit()!=rate.Inf{t.Fatal("disable limit did not reach existing connections")}
 ctx,cancel:=context.WithCancel(context.Background());cancel();b.SetRate(1);if b.Wait(ctx,true,128*1024)==nil{t.Fatal("cancelled wait succeeded")}
}
