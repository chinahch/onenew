// Compile generated caddy envelopes with the pinned Xray native config builder.
package main

import (
  "encoding/json"
  "fmt"
  "os"

  "github.com/xtls/xray-core/infra/conf"
)

func main() {
  var envelopes []map[string]json.RawMessage
  if err:=json.NewDecoder(os.Stdin).Decode(&envelopes);err!=nil{panic(err)}
  for index,envelope:=range envelopes {
    // The exact wire promotion applied by caddy/scripts/apply_landing.py.
    if stream,ok:=envelope["stream_settings"];ok{envelope["streamSettings"]=stream;delete(envelope,"stream_settings")}
    raw,err:=json.Marshal(envelope);if err!=nil{panic(err)}
    var outbound conf.OutboundDetourConfig
    if err=json.Unmarshal(raw,&outbound);err!=nil{panic(fmt.Sprintf("fixture %d decoding failed",index))}
    built,err:=outbound.Build();if err!=nil{panic(fmt.Sprintf("fixture %d native compilation failed: %v",index,err))}
    if built==nil||built.ProxySettings==nil{panic(fmt.Sprintf("fixture %d produced no proxy settings",index))}
  }
  fmt.Printf("Pinned Xray native outbound compilation passed: %d fixtures\n",len(envelopes))
}
