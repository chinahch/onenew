export function attentionCount(data,kind){
  const count=key=>Number.isSafeInteger(data?.[key])&&data[key]>0?data[key]:0;
  return kind==='all'?count('orders')+count('energy'):count(kind);
}
const subscribers=new Set();
let snapshot={},pending=null,timer=null;
async function refresh(get){
  if(pending||document.hidden)return;
  pending=(async()=>{
    try{const response=await get('/api/onenew/admin/energy/attention',{baseURL:'/'});snapshot=response?.data??response;for(const fn of subscribers)fn(snapshot)}
    catch{/* Keep the last known state; a failed request is not proof the queue is empty. */}
  })();
  try{await pending}finally{pending=null}
}
export function AttentionIcon({react:R,get,kind,icon}){
  const [data,setData]=R.useState(snapshot);
  R.useEffect(()=>{
    subscribers.add(setData);const update=()=>refresh(get);
    if(!timer)timer=setInterval(update,15000);
    window.addEventListener('focus',update);window.addEventListener('onenew-attention-changed',update);document.addEventListener('visibilitychange',update);update();
    return()=>{subscribers.delete(setData);window.removeEventListener('focus',update);window.removeEventListener('onenew-attention-changed',update);document.removeEventListener('visibilitychange',update);if(!subscribers.size){clearInterval(timer);timer=null;snapshot={}}};
  },[get]);
  const count=attentionCount(data,kind),label=`${kind==='orders'?'待处理订单':kind==='energy'?'待审核能量申请':'待处理事项'} ${count} 项`;
  return R.createElement('span',{style:{position:'relative',display:'inline-flex'},title:count?label:undefined},icon,count>0&&R.createElement('span',{'aria-label':label,role:'status',style:{position:'absolute',right:-5,top:-3,width:7,height:7,borderRadius:'50%',background:'#ef4444',boxShadow:'0 0 0 2px var(--background, white)'}}));
}
