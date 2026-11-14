// SubscriptionGuard by XBoard Plugin; local, text-only Onenew audit dashboard.
(()=>{
  'use strict';
  const get=id=>document.getElementById(id),base=get('app').dataset.api;
  let page=1,busy=false;
  function token(){try{const value=JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN')||'null')?.value;return typeof value==='string'?value:''}catch{return ''}}
  async function api(endpoint,body){
    const auth=token();if(!auth)throw new Error('请先登录管理后台，再刷新此页面。');
    const url=new URL(base+endpoint,window.location.origin);
    const response=await fetch(url.toString(),{method:body?'POST':'GET',credentials:'omit',cache:'no-store',headers:{Accept:'application/json',Authorization:auth,...(body?{'Content-Type':'application/json'}:{})},...(body?{body:JSON.stringify(body)}:{})});
    if(!response.ok)throw new Error(response.status===403?'管理员身份无效或权限不足。':'请求失败：'+response.status);
    return response.json();
  }
  function cell(text){const element=document.createElement('td');element.textContent=String(text??'—');return element}
  async function act(action,id){
    if(!Number.isSafeInteger(Number(id))||Number(id)<1)return;
    const prompt=action==='ban'?'确认封禁此用户并重置订阅？':'确认解封此用户并复核历史拉取记录？';
    if(!window.confirm(prompt))return;
    try{await api('/'+action,{user_id:Number(id),confirmed:true});await load()}catch(error){get('status').textContent=error.message}
  }
  function render(rows){
    get('rows').replaceChildren();
    for(const item of rows){
      const row=document.createElement('tr');
      row.append(cell(item.email||'#'+item.user_id),cell(item.ip??item.unique_ip_count??item.unique_ips),cell(item.user_agent??item.action_taken??('拉取 '+item.total_pulls+' 次')),cell(item.created_at??item.last_pull));
      const controls=document.createElement('td');
      if(get('view').value!=='logs'){
        const button=document.createElement('button');button.textContent=item.banned?'解封并复核':'封禁并重置';
        button.addEventListener('click',()=>act(item.banned?'unban':'ban',item.user_id));controls.append(button);
      }
      row.append(controls);get('rows').append(row);
    }
  }
  async function load(){
    if(busy)return;busy=true;get('status').textContent='正在读取…';
    try{
      const view=get('view').value,query=new URLSearchParams({page:String(page),page_size:'20',...(get('search').value?{search:get('search').value}:{})});
      const result=await api('/'+view+'?'+query);
      render(Array.isArray(result.data)?result.data:[]);get('status').textContent='第 '+page+' 页，共 '+(result.total||0)+' 条';
      get('previous').disabled=page<=1;get('next').disabled=page*20>=(result.total||0);
    }catch(error){get('rows').replaceChildren();get('status').textContent=error.message;get('previous').disabled=true;get('next').disabled=true}
    finally{busy=false}
  }
  get('refresh').addEventListener('click',()=>{page=1;load()});
  get('view').addEventListener('change',()=>{page=1;load()});
  get('previous').addEventListener('click',()=>{if(!busy&&page>1){page--;load()}});
  get('next').addEventListener('click',()=>{if(!busy){page++;load()}});
  load();
})();
