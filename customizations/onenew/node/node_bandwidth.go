package limiter
import("context";"golang.org/x/time/rate")
type NodeBandwidth struct{Up,Down *rate.Limiter}
func NewNodeBandwidth()*NodeBandwidth{return &NodeBandwidth{rate.NewLimiter(rate.Inf,65536),rate.NewLimiter(rate.Inf,65536)}}
func(b *NodeBandwidth)SetRate(mbps int){limit:=rate.Inf;if mbps>0{limit=rate.Limit(int64(mbps)*1000000/8)};b.Up.SetLimit(limit);b.Down.SetLimit(limit)}
func(b *NodeBandwidth)Wait(ctx context.Context,upload bool,n int)error{if b==nil{return nil};lim:=b.Down;if upload{lim=b.Up};for n>0{size:=n;if size>lim.Burst(){size=lim.Burst()};if err:=lim.WaitN(ctx,size);err!=nil{return err};n-=size};return nil}
