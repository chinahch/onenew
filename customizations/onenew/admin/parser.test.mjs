import test from 'node:test';
import assert from 'node:assert/strict';
import {parseLink, planRules, healthLabel} from './src/rules.mjs';

const base={core:'singbox',tag:'landing',services:['4']};
test('SOCKS auth accepts raw/standard/url-safe Base64 including slash and IPv6',()=>{
  for(const auth of ['user:p?word', '用户:pa?ss', 'a:???']) {
    for(const format of ['base64','base64url']) for(const padded of [true,false]) {
      let encoded=Buffer.from(auth).toString(format); if(!padded)encoded=encoded.replace(/=+$/,'');
      const o=parseLink(`socks://${encoded}@[2001:db8::1]:1080#demo`,'singbox','landing');
      assert.equal(o.settings.username,auth.split(':')[0]); assert.equal(o.settings.password,auth.split(':')[1]);
      assert.equal(o.settings.server,'2001:db8::1'); assert.equal(o.settings.server_port,1080);
    }
  }
});
test('SOCKS percent credentials and Xray native settings',()=>{
  assert.deepEqual(parseLink('socks5://hello:p%40ss@exit.example:1080','xray','landing'),{tag:'landing',protocol:'socks',settings:{servers:[{address:'exit.example',port:1080,users:[{user:'hello',pass:'p@ss'}]}]}});
  const s=parseLink('socks://hello%3Aworld:p%3Aa%40ss@exit.example:1080','singbox','landing').settings;
  assert.equal(s.username,'hello:world');assert.equal(s.password,'p:a@ss');
});
test('invalid ports, unknown parameters and unsupported protocols fail closed',()=>{
  for(const link of ['socks://exit.example:0','socks://exit.example:65536','socks://bad:1080?tls=1','socks://!!!@exit.example:1080','http://exit.example:80']) assert.throws(()=>parseLink(link,'singbox','landing'));
});
const reality='vless://00000000-0000-4000-8000-000000000001@exit.example:443?security=reality&sni=tls.example&fp=chrome&pbk=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA&sid=abcd&flow=xtls-rprx-vision';
test('Sing-box REALITY survives with all TLS fields',()=>{
  const s=parseLink(reality,'singbox','landing').settings;
  assert.deepEqual(s.tls,{enabled:true,server_name:'tls.example',utls:{enabled:true,fingerprint:'chrome'},reality:{enabled:true,public_key:'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',short_id:'abcd'}});
  assert.equal(s.flow,'xtls-rprx-vision');
});

test('VLESS TCP headerType=none is a no-op for Sing-box and native Xray without losing REALITY or Vision',()=>{
  for(const core of ['singbox','xray-caddy']) {
    const plain=reality+'&encryption=none&type=tcp';
    assert.deepEqual(parseLink(plain+'&headerType=none',core,'landing'),parseLink(plain,core,'landing'));
  }
  const tcp='vless://00000000-0000-4000-8000-000000000001@exit.example:1080?encryption=none&type=tcp';
  assert.deepEqual(parseLink(tcp+'&headerType=none','xray','landing'),parseLink(tcp,'xray','landing'));
});

test('VLESS nontrivial or conflicting TCP headers still refuse instead of silently losing camouflage',()=>{
  const prefix='vless://00000000-0000-4000-8000-000000000001@exit.example:443?';
  for(const core of ['singbox','xray','xray-caddy'])for(const query of ['type=tcp&headerType=http','type=tcp&headerType=','type=ws&headerType=none','type=tcp&headerType=none&headerType=none']) {
    assert.throws(()=>parseLink(prefix+query,core,'landing'));
  }
});
test('Xray rejects TLS/REALITY/WS and VLESS ENC instead of dropping fields',()=>{
  for(const link of [reality,reality.replace('security=reality','security=tls'),'vless://id@exit.example:80?type=ws&path=%2Fws','vless://id@exit.example:80?encryption=mlkem768x25519plus.native.0rtt.abc']) assert.throws(()=>parseLink(link,'xray','landing'));
  assert.throws(()=>parseLink('vless://id@exit.example:80?encryption=foo','singbox','landing'));
});
test('Sing-box WebSocket uses native transport and rejects missing REALITY keys',()=>{
  assert.deepEqual(parseLink('vless://00000000-0000-4000-8000-000000000001@exit.example:80?type=ws&path=%2Fedge&host=cdn.example','singbox','landing').settings.transport,{type:'ws',path:'/edge',headers:{Host:'cdn.example'}});
  assert.throws(()=>parseLink(reality.replace('&pbk=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',''),'singbox','landing'));
});
test('replace all uses native Xray routes and removes every previous outbound',()=>{
  const p=planRules({...base,core:'xray',link:'socks://exit.example:1080',oldOutbounds:[{tag:'old'}],oldRoutes:[{outbound:'old'}]});
  assert.equal(p.outbounds.length,1); assert.equal(p.outbounds[0].settings.servers[0].address,'exit.example');
  assert.deepEqual(p.routes,[{type:'field',domain:['domain:youtube.com','domain:youtu.be','domain:googlevideo.com','domain:ytimg.com'],outboundTag:'landing'}]);
});
test('global routes are kernel-native and custom domain input never silently drops invalid items',()=>{
  assert.deepEqual(planRules({...base,services:['g'],link:'socks://exit.example:1080'}).routes,[{outbound:'landing'}]);
  assert.throws(()=>planRules({...base,services:['c'],customDomains:'example.com,geosite:cn',link:'socks://exit.example:1080'}));
});
test('preserve removes selected domain only and preserves unselected outbound',()=>{
  const p=planRules({...base,mode:'preserve',link:'socks://new.example:1080',oldOutbounds:[{tag:'old',protocol:'socks',settings:{server:'old.example',server_port:1080}}],oldRoutes:[{outbound:'old',domain_suffix:['youtube.com','twitter.com']}]});
  assert.deepEqual(p.routes.at(-1),{outbound:'old',domain_suffix:['twitter.com']}); assert.equal(p.outbounds.length,2);
});
test('preserve refuses complex route overlap and shared tag collisions',()=>{
  assert.throws(()=>planRules({...base,mode:'preserve',link:'socks://new.example:1080',oldRoutes:[{outbound:'old',domain_suffix:['youtube.com'],port:443}]}));
  assert.throws(()=>planRules({...base,mode:'preserve',link:'socks://new.example:1080',oldOutbounds:[{tag:'landing',protocol:'socks',settings:{server:'old.example',server_port:1080}}],oldRoutes:[{outbound:'landing',domain_suffix:['twitter.com']}]}));
});
test('node status uses real proxy result freshness, never heartbeat',()=>{
  const now=Date.parse('2026-11-14T12:00:00Z');
  assert.equal(healthLabel({proxy_health:{state:'succeeded',health_state:'online',finished_at:'2026-11-14T11:59:00Z'}},now).label,'连通在线');
  assert.equal(healthLabel({proxy_health:{state:'failed',health_state:'node_fault',finished_at:'2026-11-14T11:59:00Z'}},now).label,'节点故障');
  for(const h of [{state:'succeeded',finished_at:'2026-11-14T11:50:00Z'},{state:'succeeded',finished_at:'2026-11-14T12:05:00Z'},{state:'running'}]) assert.equal(healthLabel({proxy_health:h,last_check_at:now},now).label,'待检测');
  assert.equal(healthLabel({last_check_at:now},now).label,'待检测');
  assert.equal(healthLabel({proxy_health:{state:'unsupported'}},now).label,'不支持检测');
  assert.equal(healthLabel({proxy_health:{state:'pending',error_code:'configuration_unavailable'}},now).label,'配置不可用');
});
test('Shadowsocks and VMess produce native envelopes for both kernels',()=>{
  const ss='ss://'+Buffer.from('aes-128-gcm:example-password').toString('base64url')+'@exit.example:443';
  assert.deepEqual(parseLink(ss,'xray','landing').settings,{servers:[{address:'exit.example',port:443,method:'aes-128-gcm',password:'example-password'}]});
  const vmess='vmess://'+Buffer.from(JSON.stringify({add:'exit.example',port:80,id:'00000000-0000-4000-8000-000000000001',aid:0,net:'tcp',scy:'auto'})).toString('base64');
  assert.deepEqual(parseLink(vmess,'xray','landing').settings.vnext[0].users,[{id:'00000000-0000-4000-8000-000000000001',alterId:0,security:'auto'}]);
  assert.equal(parseLink(vmess,'singbox','landing').settings.server_port,80);
});
test('Hysteria2 and TUIC retain authentication, TLS and transport options',()=>{
  const s=parseLink('hy2://example-password@exit.example:443/?sni=tls.example&obfs=salamander&obfs-password=example-obfs&upmbps=20&downmbps=100','singbox','landing').settings;
  assert.deepEqual(s.obfs,{type:'salamander',password:'example-obfs'});assert.equal(s.up_mbps,20);assert.equal(s.down_mbps,100);assert.equal(s.tls.server_name,'tls.example');
  const t=parseLink('tuic://00000000-0000-4000-8000-000000000001:example-password@exit.example:443?alpn=h3&congestion_control=bbr&udp_relay_mode=native','singbox','landing').settings;
  assert.equal(t.password,'example-password');assert.equal(t.congestion_control,'bbr');assert.deepEqual(t.tls.alpn,['h3']);
  assert.throws(()=>parseLink('hy2://exit.example:443','singbox','landing'),/密码/);
});
test('preserve refuses old native settings from the other kernel and duplicate tags',()=>{
  assert.throws(()=>planRules({...base,mode:'preserve',link:'socks://new.example:1080',oldOutbounds:[{tag:'old',protocol:'socks',settings:{servers:[{address:'old.example',port:1080}]}}]}),/内核/);
  assert.throws(()=>planRules({...base,mode:'preserve',link:'socks://new.example:1080',oldOutbounds:[{tag:'old'},{tag:'old'}]}),/重复/);
});
test('Binance service replacement includes existing subdomains without removing Binance.US',()=>{
  const p=planRules({...base,services:['20'],mode:'preserve',link:'socks://new.example:1080',oldOutbounds:[{tag:'old',protocol:'socks',settings:{server:'old.example',server_port:1080}}],oldRoutes:[{outbound:'old',domain_suffix:['stream.binance.com','binance.us']}]});
  assert.deepEqual(p.routes.at(-1),{outbound:'old',domain_suffix:['binance.us']});
});
const encRealityFixture='vless://00000000-0000-4000-8000-000000000001@exit.example:20200?encryption=mlkem768x25519plus.native.0rtt.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA&flow=xtls-rprx-vision&security=reality&sni=addons.mozilla.org&fp=chrome&pbk=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA&sid=7d460428&spx=%2Fprobe%3Fx%3D1&type=tcp&headerType=none#Landing';
test('explicit caddy mode preserves VLESS ENC, flow and complete REALITY stream settings',()=>{
  const out=parseLink(encRealityFixture,'xray-caddy','landing');
  assert.deepEqual(out,{tag:'landing',protocol:'vless',settings:{vnext:[{address:'exit.example',port:20200,users:[{id:'00000000-0000-4000-8000-000000000001',encryption:'mlkem768x25519plus.native.0rtt.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',flow:'xtls-rprx-vision'}]}]},stream_settings:{network:'tcp',security:'reality',realitySettings:{serverName:'addons.mozilla.org',publicKey:'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',shortId:'7d460428',fingerprint:'chrome',spiderX:'/probe?x=1'}}});
  assert.throws(()=>parseLink(encRealityFixture,'xray','landing'));
  assert.throws(()=>parseLink(encRealityFixture,'singbox','landing'));
});
test('caddy mode emits Xray-native XHTTP, WS and gRPC without dropping parameters',()=>{
  const prefix='vless://00000000-0000-4000-8000-000000000001@exit.example:443?';
  assert.deepEqual(parseLink(prefix+'type=xhttp&path=%2Fedge&host=cdn.example&mode=stream-up&security=tls&sni=tls.example&fp=chrome&alpn=h2%2Chttp%2F1.1','xray-caddy','landing').stream_settings,{network:'xhttp',security:'tls',tlsSettings:{serverName:'tls.example',fingerprint:'chrome',alpn:['h2','http/1.1']},xhttpSettings:{path:'/edge',host:'cdn.example',mode:'stream-up'}});
  assert.deepEqual(parseLink(prefix+'type=ws&path=%2Fws&host=cdn.example','xray-caddy','landing').stream_settings,{network:'ws',security:'none',wsSettings:{path:'/ws',headers:{Host:'cdn.example'}}});
  assert.deepEqual(parseLink(prefix+'type=grpc&serviceName=edge&security=tls&peer=tls.example','xray-caddy','landing').stream_settings,{network:'grpc',security:'tls',tlsSettings:{serverName:'tls.example'},grpcSettings:{serviceName:'edge'}});
});
test('caddy pin fields reach TLS and unsupported combinations are rejected',()=>{
  const prefix='vless://00000000-0000-4000-8000-000000000001@exit.example:443?';
  const pin='ab'.repeat(32);
  assert.deepEqual(parseLink(prefix+'security=tls&pcs='+pin+'&vcn=tls.example','xray-caddy','landing').stream_settings.tlsSettings,{serverName:'exit.example',pinnedPeerCertSha256:pin,verifyPeerCertByName:'tls.example'});
  for(const q of ['security=tls&insecure=1','type=xhttp&mode=invalid','type=tcp&path=%2Fignored','type=ws&mode=stream-up','security=none&fp=chrome','security=tls&spx=%2Fprobe','security=tls&pcs=bad','encryption=unsupported','type=xhttp&unknown=1','type=grpc&host=ignored','security=tls&sni=one.example&peer=two.example']) assert.throws(()=>parseLink(prefix+q,'xray-caddy','landing'));
  assert.throws(()=>parseLink(encRealityFixture.split('#')[0]+'&alpn=h2','xray-caddy','landing'));
});
test('caddy routes and preserved rules use Xray native dialect',()=>{
  const global=planRules({...base,core:'xray-caddy',services:['g'],link:encRealityFixture});
  assert.deepEqual(global.routes,[{type:'field',network:'tcp,udp',outboundTag:'landing'}]);
  const preserved=planRules({...base,core:'xray-caddy',mode:'preserve',link:encRealityFixture,oldOutbounds:[{tag:'old',protocol:'socks',settings:{servers:[{address:'old.example',port:1080}]}}],oldRoutes:[{type:'field',outboundTag:'old',domain:['domain:youtube.com','domain:twitter.com']}]});
  assert.deepEqual(preserved.routes.at(-1),{type:'field',outboundTag:'old',domain:['domain:twitter.com']});
  assert.ok(preserved.outbounds.find(o=>o.tag==='landing').stream_settings.realitySettings);
});
test('reserved outbound tags cannot suppress built-in routing for any target kernel',()=>{
  for(const core of ['singbox','xray','xray-caddy'])for(const tag of ['direct','DIRECT','Block','dns-out','DNS-OUT','__xboard_ipfamily_deny','__XBOARD_IPFAMILY_DENY']){
    assert.throws(()=>parseLink('socks://exit.example:1080',core,tag),/保留/);
    assert.throws(()=>planRules({...base,core,tag,link:'socks://exit.example:1080'}),/保留/);
  }
});

test('health distinguishes an active or expired task from a generic pending state',()=>{
 const now=Date.parse('2026-11-14T12:00:00Z');
 assert.equal(healthLabel({proxy_health:{state:'running',created_at:'2026-11-14T11:59:00Z'}},now).label,'检测中');
 assert.equal(healthLabel({proxy_health:{state:'expired',created_at:'2026-11-14T11:55:00Z'}},now).label,'检测超时');
});

test('stages distinguish landing faults and legacy unknown without exposing error objects',()=>{
 const now=Date.now(),finished_at=new Date(now).toISOString();
 for(const [health_state,label] of Object.entries({landing_fault:'落地故障',partial_landing_fault:'部分落地故障',unknown:'检测异常'}))assert.equal(healthLabel({proxy_health:{state:'succeeded',health_state,finished_at}},now).label,label);
 assert.equal(healthLabel({proxy_health:{state:'succeeded',finished_at}},now).label,'检测异常');
});

test('ambiguous transport failures stay detection abnormal with safe detail',()=>{
 const finished_at=new Date().toISOString();
 const view=healthLabel({proxy_health:{state:'failed',health_state:'unknown',error_code:'transport_failed',finished_at}});
 assert.equal(view.label,'检测异常');
 assert.match(view.detail,/连接失败，无法确定入口\/目标/);
});
