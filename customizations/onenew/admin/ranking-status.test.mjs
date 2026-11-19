import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {RankingStatus,rankingParameters} from './src/ranking-status.mjs';
import {patchBundle} from './patch.mjs';

const react={createElement:(type,props,...children)=>({type,props:props??{},children})};
const render=query=>RankingStatus({react,query});
test('ranking distinguishes pending, empty success and failed requests',()=>{
  assert.match(JSON.stringify(render({isPending:true})),/加载中/);
  const empty=render({isPending:false,isError:false});
  assert.match(JSON.stringify(empty),/暂无流量数据/);
  assert.doesNotMatch(JSON.stringify(empty),/加载中|animate-pulse/);
  const error=render({isPending:false,isError:true,refetch(){}});
  assert.equal(error.props.role,'alert');
  assert.match(JSON.stringify(error),/流量排行加载失败/);
  assert.doesNotMatch(JSON.stringify(error),/加载中|暂无流量数据/);
});
test('failed ranking retries its own request and disables repeat clicks while fetching',()=>{
  let count=0;
  const query={isPending:false,isError:true,isFetching:false,refetch(){count++}};
  render(query).children[1].props.onClick();
  assert.equal(count,1);
  query.isFetching=true;
  const button=render(query).children[1];
  assert.equal(button.props.disabled,true);
  assert.deepEqual(button.children,['正在重试…']);
});
test('both real dashboard ranking cards use their own query state and retain populated rankings',()=>{
  const here=path.dirname(fileURLToPath(import.meta.url));
  const upstream=process.env.ONENEW_ADMIN_UPSTREAM||path.resolve(here,'../../../../xboard-admin-dist');
  const source=fs.readFileSync(path.join(upstream,'assets/index-CEIYH7i8.js'),'utf8');
  const patched=patchBundle(source,'');
  const ranking=patched.slice(patched.indexOf('function eKt('),patched.indexOf('const tKt=',patched.indexOf('function eKt(')));
  assert.ok(ranking.includes('OnenewAdmin.RankingStatus,{react:H,query:h}'));
  assert.ok(ranking.includes('OnenewAdmin.RankingStatus,{react:H,query:g}'));
  assert.ok(ranking.includes('p.length?Q.jsxs(Gqt'));
  assert.ok(ranking.includes('m.length?Q.jsxs(Gqt'));
  assert.ok(ranking.includes('rankingParameters("node",n,r)'));
  assert.ok(ranking.includes('rankingParameters("user",o,l)'));
  assert.doesNotMatch(ranking,/start_time:FT.round/);
  assert.ok(ranking.includes('children:p.map('));
  assert.ok(ranking.includes('children:m.map('));
  assert.doesNotMatch(ranking,/animate-pulse/);
});
test('preset periods use panel time and custom dates retain selected calendar days',()=>{
  for(const type of ['node','user'])for(const period of ['today','last7days','last30days']){
    assert.deepEqual(rankingParameters(type,period),{type,period});
  }
  assert.deepEqual(rankingParameters('node','custom',{from:new Date(2026,10,20),to:new Date(2026,10,22)}),
    {type:'node',period:'custom',start_date:'2026-11-20',end_date:'2026-11-22'});
  assert.throws(()=>rankingParameters('node','custom',{}));
  assert.throws(()=>rankingParameters('node','invalid'));
});
