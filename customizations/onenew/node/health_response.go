package probe

import (
 "net"
 "strings"
)

// A successful health response must be the expected bounded CF trace, not a
// login page, redirect, challenge, or arbitrary HTTP 200 response.
func validHealthResponse(target string, status int, body []byte) bool {
 // Accept old panel tasks only during a rolling upgrade; never retry over HTTP.
 if target == "http://cp.cloudflare.com/generate_204" { return status == 204 }
 // Task URL allowlisting happens in runTask before execute is called.
 if status != 200 || len(body) > 4096 { return false }
 var ip, colo string
 for _, line := range strings.Split(string(body), "\n") {
  key, value, found := strings.Cut(strings.TrimSpace(line), "=")
  if !found {continue}
  switch key {
  case "ip":
   if ip != "" {return false}
   ip = value
  case "colo":
   if colo != "" {return false}
   colo = value
  }
 }
 if net.ParseIP(ip) == nil || len(colo) != 3 {return false}
 for _, letter := range colo {if letter < 'A' || letter > 'Z' {return false}}
 return true
}
