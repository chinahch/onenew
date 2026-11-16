package probe
import("testing";"net/http";"strings")
func TestHTTPSCloudflareTraceResponses(t *testing.T){
 for _,tc:=range []struct{name,body string;status int;ok bool}{
  {"ipv4","ip=203.0.113.4\ncolo=LAX\nloc=US\n",http.StatusOK,true},
  {"ipv6","ip=2001:db8::4\ncolo=LAS\nloc=US\n",http.StatusOK,true},
  {"wrong status","ip=203.0.113.4\ncolo=LAX\n",http.StatusNoContent,false},
  {"HTML","<html>challenge</html>",http.StatusOK,false},
  {"bad IP","ip=invalid\ncolo=LAX\n",http.StatusOK,false},
  {"missing colo","ip=203.0.113.4\n",http.StatusOK,false},
  {"oversized","ip=203.0.113.4\ncolo=LAX\n"+strings.Repeat("x",4096),http.StatusOK,false},
  {"bad colo","ip=203.0.113.4\ncolo=123\n",http.StatusOK,false},
  {"duplicate IP","ip=203.0.113.4\nip=203.0.113.5\ncolo=LAX\n",http.StatusOK,false},
 }{t.Run(tc.name,func(t *testing.T){if validHealthResponse(Target,tc.status,[]byte(tc.body))!=tc.ok{t.Fatal("unexpected health classification")}})}
 if !validHealthResponse("http://cp.cloudflare.com/generate_204",204,nil){t.Fatal("rolling upgrade compatibility")}
}
