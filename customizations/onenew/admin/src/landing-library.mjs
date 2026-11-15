import {parseLink,serviceLabels} from './rules.mjs';
import catalog from './catalog.json' with {type:'json'};
import {resolveLandingCore,coreLabels} from './landing-state.mjs';
import {LandingProbe} from './landing-probe.mjs';
const root='/api/onenew/admin/landings',opts={baseURL:'/'};
const unwrap=v=>v?.data??v;
const message=e=>e?.response?.data?.message||e?.message||'请求失败，请重试';
export function buildLandingVariants(link){
 const variants={},unsupported={};
 for(const core of ['singbox','xray','xray-caddy']){try{variants[core]=parseLink(link,core,'landing')}catch(e){unsupported[core]=message(e)}}
 if(!Object.keys(variants).length)throw new Error(Object.values(unsupported).join('；'));
 return {variants,unsupported};
}
export function publicLandingName(link){
 const fragment=String(link).split('#').slice(1).join('#');
 if(fragment){try{return decodeURIComponent(fragment).trim()}catch{throw Error('链接备注编码无效')}}
 if(/^vmess:\/\//i.test(link)){try{const value=link.slice(link.indexOf('://')+3).replace(/-/g,'+').replace(/_/g,'/');const json=JSON.parse(new TextDecoder('utf-8',{fatal:true}).decode(Uint8Array.from(atob(value),c=>c.charCodeAt(0))));return String(json.ps||'').trim()}catch{throw Error('VMess 名称编码无效')}}
 return '';
}
export function toggleLandingService(existing,key,checked){
 const preset=catalog[key];if(!preset)throw Error('未知服务预设');
 return checked?[...new Set([...existing,...preset])]:existing.filter(d=>!preset.includes(d));
}
const domains=text=>[...new Set(String(text).split(/[\s,，]+/).map(x=>x.trim().toLowerCase()).filter(Boolean))];
export function validateLandingBindings(bindings){
 if(new Set(bindings.map(b=>Number(b.landing_id))).size!==bindings.length)return '同一落地不能重复选择';
 const ids=new Set(),seen=[];
 for(let i=0;i<bindings.length;i++){
  const b=bindings[i];if(!b.landing_id)return '请选择落地';if(ids.has(Number(b.landing_id)))return '同一落地不能重复选择';ids.add(Number(b.landing_id));
  if(b.global){if(i!==bindings.length-1)return '全局落地必须只有一个并放在最后';continue}
  if(!b.domains.length)return '请选择服务或填写域名';
  for(const d of b.domains){if(!/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(d))return '域名格式无效';if(seen.some(x=>x.owner!==Number(b.landing_id)&&(x.domain===d||x.domain.endsWith('.'+d)||d.endsWith('.'+x.domain))))return '域名规则重叠，请合并或调整';seen.push({domain:d,owner:Number(b.landing_id)})}
 }
 return '';
}
function button(h,label,onClick,disabled=false){return h('button',{type:'button',className:'onenew-button',onClick,disabled},label)}
export function LandingLibraryButton({react:React,get,post}){
 const h=React.createElement,[open,setOpen]=React.useState(false),[items,setItems]=React.useState([]),[draft,setDraft]=React.useState(null),[batch,setBatch]=React.useState(''),[error,setError]=React.useState(''),[status,setStatus]=React.useState(''),[busy,setBusy]=React.useState(false),generation=React.useRef(0);
 React.useEffect(()=>{if(!open)return;let active=true;setBusy(true);get(root,opts).then(v=>{if(active)setItems(unwrap(v))}).catch(e=>{if(active)setError(message(e))}).finally(()=>{if(active)setBusy(false)});return()=>{active=false;generation.current++}},[open,get]);
 async function refresh(){setItems(unwrap(await get(root,opts)))}
 async function edit(id){const token=++generation.current;setBusy(true);setError('');try{const data=unwrap(await get(`${root}/${id}`,opts));if(token===generation.current){setDraft(data);setBatch('')}}catch(e){if(token===generation.current)setError(message(e))}finally{if(token===generation.current)setBusy(false)}}
 async function save(){const token=++generation.current;setBusy(true);setError('');setStatus('');try{
  if(draft){const parsed=buildLandingVariants(draft.link);await post(draft.id?`${root}/${draft.id}`:root,{name:draft.name,link:draft.link,variants:parsed.variants,enabled:!!draft.enabled,sort:Number(draft.sort)||0,...(draft.id?{version:draft.version}:{})},opts);if(token!==generation.current)return;setDraft(null);setStatus('已保存，受影响节点等待同步。'+(Object.keys(parsed.unsupported).length?' 不支持的内核：'+Object.keys(parsed.unsupported).map(key=>coreLabels[key]+'（'+parsed.unsupported[key]+'）').join('；'):''))}
  else {const lines=batch.split(/\r?\n/).map(x=>x.trim()).filter(Boolean);if(!lines.length)throw Error('请填写落地链接');let count=0;for(const link of lines){try{const parsed=buildLandingVariants(link);await post(root,{name:publicLandingName(link),link,variants:parsed.variants,enabled:true,sort:0},opts);count++;if(token!==generation.current)return;setBatch(lines.slice(count).join('\n'))}catch(e){throw Error(`已导入 ${count} 条；第 ${count+1} 条失败：${message(e)}`)}}setStatus(`已导入 ${count} 条，等待节点同步。`)}
  if(token===generation.current)await refresh();
 }catch(e){if(token===generation.current){setError(message(e));try{await refresh()}catch{}}}finally{if(token===generation.current)setBusy(false)}}
 async function remove(item){if(item.reference_count)return;setBusy(true);setError('');try{await post(`${root}/${item.id}/delete`,{version:item.version},opts);await refresh();setStatus('已删除落地')}catch(e){setError(message(e))}finally{setBusy(false)}}
 const field=(label,key,type='text')=>h('label',null,label,h('input',{type,value:draft[key]??'',onChange:e=>setDraft({...draft,[key]:e.target.value}),autoComplete:'off'}));
 return h(React.Fragment,null,button(h,'添加落地',()=>{setOpen(true);setError('');setStatus('')}),open&&h('div',{className:'onenew-library-overlay'},h('section',{role:'dialog','aria-modal':true,'aria-label':'落地库',className:'onenew-library-dialog'},h('h2',null,'落地库'),h('p',null,'添加复用落地后，在节点主表单选择分流范围。链接含凭据，仅管理员可查看。'),error&&h('p',{role:'alert'},error),status&&h('p',{role:'status'},status),h('div',{className:'onenew-library-list'},...items.map(item=>h('div',{key:item.id,className:'onenew-library-item'},h('span',null,`${item.name} · ${item.protocol} · ${item.host}:${item.port} · ${item.enabled?'启用':'停用'} · 引用 ${item.reference_count||0}`),button(h,'编辑',()=>edit(item.id),busy),button(h,'删除',()=>remove(item),busy||!!item.reference_count)))),draft?h('div',{className:'onenew-library-editor'},field('名称','name'),h('label',null,'原始链接',h('textarea',{value:draft.link||'',rows:3,onChange:e=>setDraft({...draft,link:e.target.value}),autoComplete:'off',spellCheck:false})),field('排序','sort','number'),h('label',null,h('input',{type:'checkbox',checked:!!draft.enabled,onChange:e=>setDraft({...draft,enabled:e.target.checked})}),'启用'),h('p',null,'保存将更新引用此落地的节点配置；节点实际应用需等待同步。'),button(h,'保存落地',save,busy),button(h,'取消编辑',()=>setDraft(null),busy)):h('div',null,button(h,'新建单条',()=>{setDraft({name:'',link:'',enabled:true,sort:0});setBatch('')},busy),h('label',null,'批量导入（每行一个链接）',h('textarea',{value:batch,rows:3,onChange:e=>setBatch(e.target.value),autoComplete:'off',spellCheck:false})),button(h,'逐条导入',save,busy||!batch.trim())),button(h,'关闭',()=>{generation.current++;setOpen(false);setDraft(null);setBatch('');setBusy(false)},false))));
}
export function NodeLandingField({react:React,form,get,post}){
 const h=React.createElement,[values,setValues]=React.useState(()=>form.getValues()),[items,setItems]=React.useState([]),[details,setDetails]=React.useState({}),[error,setError]=React.useState(''),[confirm,setConfirm]=React.useState(''),[loading,setLoading]=React.useState(true);
 React.useEffect(()=>{const watch=form.watch(v=>setValues({...v}));setValues({...form.getValues()});return()=>typeof watch==='function'?watch():watch?.unsubscribe?.()},[form]);
 React.useEffect(()=>{let active=true;get(root,opts).then(v=>{if(active)setItems(unwrap(v))}).catch(e=>{if(active)setError(message(e))}).finally(()=>{if(active)setLoading(false)});return()=>{active=false}},[get]);
 const bindings=values.onenew_landing_bindings??null,nodeId=values.id,machineId=values.machine_id;
 React.useEffect(()=>{if(!Number(nodeId))return;let active=true,sequence=0;async function refresh(){const token=++sequence;try{const report=unwrap(await get(`/api/onenew/admin/node-runtime/${Number(nodeId)}`,opts));if(active&&token===sequence&&(!Number(machineId)||report.machine_id===Number(machineId)))form.setValue('metrics',report,{shouldDirty:false})}catch{}}refresh();const timer=setInterval(refresh,10000);return()=>{active=false;sequence++;clearInterval(timer)}},[nodeId,machineId,get,form]);
 const ids=JSON.stringify((bindings||[]).map(b=>b.landing_id));
 React.useEffect(()=>{let active=true;setDetails({});Promise.all((bindings||[]).filter(b=>b.landing_id).map(async b=>[b.landing_id,unwrap(await get(`${root}/${b.landing_id}`,opts))])).then(entries=>{if(active)setDetails(Object.fromEntries(entries))}).catch(e=>{if(active)setError(message(e))});return()=>{active=false}},[ids,get]);
 function change(next){setError(validateLandingBindings(next));form.setValue('onenew_landing_bindings',next,{shouldDirty:true,shouldValidate:true});setValues({...form.getValues(),onenew_landing_bindings:next})}
 const core=resolveLandingCore(values.metrics||values.runtime_metrics,values.network||values.protocol_settings?.network),validation=bindings?validateLandingBindings(bindings):'';
 const legacy=!!(values.custom_outbounds?.length||values.custom_routes?.length);
 let native=[];try{native=typeof values.custom_outbounds==='string'?JSON.parse(values.custom_outbounds||'[]'):values.custom_outbounds||[]}catch{}
 const metadata=Array.isArray(native)?native.map(out=>{const settings=out.settings||{},server=settings.servers?.[0]||settings.vnext?.[0]||settings;return `${out.tag||'未命名'} · ${out.protocol||out.type||'未知协议'} · ${server.address||server.server||out.server||'未知地址'}:${server.port||server.server_port||out.server_port||'?'}`}):[];
 function update(index,patch){change(bindings.map((b,i)=>i===index?{...b,...patch}:b))}
 return h('section',{className:'onenew-node-landings','aria-label':'节点落地'},h('h3',null,'落地分流'),h('p',null,`运行内核：${coreLabels[core]||'等待机器上报'}。更改仅写入当前草稿，保存节点后等待同步。`),loading&&h('p',{role:'status'},'正在读取落地库…'),error&&h('p',{role:'alert'},error),validation&&validation!==error&&h('p',{role:'alert'},validation),bindings===null?h('div',null,h('p',null,legacy?'现有配置（未加入落地库）':'当前直连，未选择落地。'),...metadata.map((value,i)=>h('p',{key:i,className:'onenew-library-metadata'},value)),legacy&&h('details',null,h('summary',null,'查看原生配置（保留不变）'),h('pre',null,JSON.stringify({custom_outbounds:values.custom_outbounds,custom_routes:values.custom_routes},null,2))),button(h,'选择落地库',()=>legacy?setConfirm('replace'):change([])),legacy&&button(h,'清空落地，直接连接',()=>setConfirm('clear'))):h('div',null,...bindings.map((b,index)=>{const detail=details[b.landing_id],outbound=detail?.variants?.[core];return h('div',{key:index,className:'onenew-library-binding'},h('label',null,'落地',h('select',{value:b.landing_id||'',onChange:e=>update(index,{landing_id:Number(e.target.value)})},h('option',{value:''},'请选择'),...items.map(item=>h('option',{key:item.id,value:item.id,disabled:!item.enabled&&item.id!==b.landing_id},`${item.name}${item.enabled?'':'（停用）'}`)))),h('label',null,'范围',h('select',{value:b.global?'global':'domains',onChange:e=>update(index,{global:e.target.value==='global',domains:[]})},h('option',{value:'domains'},'服务 / 域名'),h('option',{value:'global'},'全局（放在最后）'))),!b.global&&h('div',null,h('fieldset',{className:'onenew-landing-services'},h('legend',null,'服务分流（可多选）'),...Object.entries(serviceLabels).map(([key,label])=>h('label',{key,className:'onenew-landing-service'},h('input',{type:'checkbox','aria-label':label,checked:catalog[key].every(d=>b.domains.includes(d)),onChange:e=>update(index,{domains:toggleLandingService(b.domains,key,e.target.checked)})}),label))),h('textarea',{'aria-label':'域名后缀',value:b.domains.join('\n'),rows:3,onChange:e=>update(index,{domains:domains(e.target.value)}),placeholder:'example.com，每行一个'})),h('div',{className:'onenew-library-actions'},button(h,'上移',()=>{const next=[...bindings];[next[index-1],next[index]]=[next[index],next[index-1]];change(next)},index===0),button(h,'下移',()=>{const next=[...bindings];[next[index+1],next[index]]=[next[index],next[index+1]];change(next)},index===bindings.length-1),button(h,'移除',()=>change(bindings.filter((_,i)=>i!==index)))),detail&&h('p',null,`${detail.protocol||''} · ${detail.host||''}:${detail.port||''}`),detail&&core&&!outbound&&h('p',{role:'alert'},'此落地不支持当前运行内核，请更换落地。'),h(LandingProbe,{react:React,nodeId,machineId,core,outbound,get,post}))}),button(h,'添加落地',()=>change([...(bindings||[]),{landing_id:0,global:false,domains:[]}]),loading),button(h,'清空落地，直接连接',()=>setConfirm('clear')),bindings.length===0&&h('p',null,'本次保存明确清空落地，全部直接连接。')),confirm&&h('div',{role:'alertdialog','aria-label':'确认替换落地'},h('p',null,confirm==='clear'?'确认清空原生出口和分流，改为直接连接？保存节点前可取消整个编辑。':'确认使用落地库替换原生出口和分流？保存节点前可取消整个编辑。'),button(h,'确认',()=>{change([]);setConfirm('')}),button(h,'取消',()=>setConfirm(''))));
}
