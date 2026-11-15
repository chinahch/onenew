package xray
import("context";"fmt";"io";"net";"sync";"testing";"time";"encoding/binary";"github.com/cedar2025/xboard-node/internal/config";"github.com/cedar2025/xboard-node/internal/kernel";"github.com/cedar2025/xboard-node/internal/model";"golang.org/x/time/rate")
func TestNodeBandwidthActualSharedProxyAndHotUpdate(t *testing.T){
 target,err:=net.Listen("tcp","127.0.0.1:0");if err!=nil{t.Fatal(err)};defer target.Close()
 payload:=make([]byte,256*1024)
 go func(){for i:=0;i<2;i++{c,e:=target.Accept();if e!=nil{return};go func(){defer c.Close();c.SetDeadline(time.Now().Add(8*time.Second));c.Write(payload)}()}}()
 portListener,e:=net.Listen("tcp","127.0.0.1:0");if e!=nil{t.Fatal(e)};port:=portListener.Addr().(*net.TCPAddr).Port;portListener.Close()
 spec:=&model.NodeSpec{Protocol:"socks",ListenIP:"127.0.0.1",ServerPort:port,Network:"tcp",NodeSpeedLimitMbps:8,CustomRoutes:[]map[string]any{{"type":"field","ip":[]string{"127.0.0.0/8"},"outboundTag":"direct"}}}
 users:=[]model.UserSpec{{ID:1,UUID:"00000000-0000-4000-8000-000000000001"},{ID:2,UUID:"00000000-0000-4000-8000-000000000002"}}
 k:=New(config.KernelConfig{Type:"xray",LogLevel:"error",ConfigDir:t.TempDir()});if e=k.Start(spec,users,kernel.TLSCert{});e!=nil{t.Fatal(e)};defer k.Stop()
 started:=time.Now();fail:=make(chan error,2);var wg sync.WaitGroup
 for _,u:=range users{wg.Add(1);go func(uuid string){defer wg.Done();c,e:=net.DialTimeout("tcp",fmt.Sprintf("127.0.0.1:%d",port),5*time.Second);if e!=nil{fail<-e;return};defer c.Close();c.SetDeadline(time.Now().Add(8*time.Second))
 c.Write([]byte{5,1,2});hello:=make([]byte,2);if _,e=io.ReadFull(c,hello);e!=nil||hello[1]!=2{fail<-fmt.Errorf("SOCKS greeting: %v %v",hello,e);return}
 auth:=append([]byte{1,byte(len(uuid))},[]byte(uuid)...);auth=append(auth,byte(len(uuid)));auth=append(auth,[]byte(uuid)...);c.Write(auth);if _,e=io.ReadFull(c,hello);e!=nil||hello[1]!=0{fail<-fmt.Errorf("SOCKS auth: %v %v",hello,e);return}
 req:=[]byte{5,1,0,1,127,0,0,1,0,0};binary.BigEndian.PutUint16(req[8:],uint16(target.Addr().(*net.TCPAddr).Port));c.Write(req);head:=make([]byte,4);if _,e=io.ReadFull(c,head);e!=nil||head[1]!=0{fail<-fmt.Errorf("SOCKS connect: %v %v",head,e);return};n:=6;if head[3]==4{n=18};if _,e=io.CopyN(io.Discard,c,int64(n));e!=nil{fail<-e;return};if _,e=io.CopyN(io.Discard,c,int64(len(payload)));e!=nil{fail<-e;return}
 }(u.UUID)};wg.Wait();close(fail);for e:=range fail{t.Fatal(e)}
 if elapsed:=time.Since(started);elapsed<350*time.Millisecond{t.Fatalf("two users bypassed node aggregate limit: %v",elapsed)}
 before:=k.instance;bucket:=k.nodeBandwidth.Down;next:=*spec;next.NodeSpeedLimitMbps=0
 if e=k.Reload(&next,users,kernel.TLSCert{});e!=nil{t.Fatal(e)}
 if k.instance!=before||k.nodeBandwidth.Down!=bucket||bucket.Limit()!=rate.Inf{t.Fatal("bandwidth-only change restarted kernel or replaced live bucket")}
 if err:=k.nodeBandwidth.Wait(context.Background(),false,131072);err!=nil{t.Fatal(err)}
}
