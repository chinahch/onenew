package xray

import (
 "crypto/sha256"
 "encoding/hex"
 "encoding/pem"
 "testing"
 "github.com/cedar2025/xboard-node/internal/config"
 "github.com/cedar2025/xboard-node/internal/model"
)

func TestOnenewShadowsocksWSTLSActualRoundtrip(t *testing.T) {
 const uuid = "00000000-0000-4000-8000-000000000001"
 tc := lumaTestCert(t)
 n := &model.NodeSpec{Protocol:"shadowsocks", Cipher:"aes-128-gcm", ListenIP:"127.0.0.1",
  ServerPort:lumaFreePort(t), Network:"ws", TLS:1,
  TLSSettings:map[string]any{"server_name":"localhost"},
  NetworkSettings:map[string]any{"path":"/ss-ws"}}
 cfg := buildConfig(config.KernelConfig{Type:"xray",LogLevel:"error"},n,[]model.UserSpec{{ID:1,UUID:uuid}},tc)
 inbound := buildInbound(n,[]model.UserSpec{{ID:1,UUID:uuid}},tc)
 stream := inbound["streamSettings"].(M)
 if stream["network"]!="ws" || stream["security"]!="tls" {t.Fatal("SS lost WS/TLS before kernel")}
 cfg["routing"]=M{"rules":[]M{{"type":"field","ip":[]string{"127.0.0.0/8"},"outboundTag":"direct"}}}
 server:=lumaStartXray(t,cfg);defer server.Close()
 cert,_:=pem.Decode(tc.CertPEM);hash:=sha256.Sum256(cert.Bytes)
 port:=lumaFreePort(t)
 client:=lumaStartXray(t,M{"log":M{"loglevel":"error"},
  "inbounds":[]M{{"listen":"127.0.0.1","port":port,"protocol":"socks","settings":M{"auth":"noauth"}}},
  "outbounds":[]M{{"protocol":"shadowsocks","settings":M{"servers":[]M{{"address":"127.0.0.1","port":n.ServerPort,"method":"aes-128-gcm","password":uuid}}},
   "streamSettings":M{"network":"ws","security":"tls","wsSettings":M{"path":"/ss-ws"},
    "tlsSettings":M{"serverName":"localhost","pinnedPeerCertSha256":hex.EncodeToString(hash[:])}}}}})
 defer client.Close()
 lumaProbeSocks(t,port)
}
