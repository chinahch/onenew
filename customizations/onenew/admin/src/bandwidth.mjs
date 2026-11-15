export function NodeBandwidthField({react:React,form}){
 const h=React.createElement,[value,setValue]=React.useState(Number(form.getValues('node_speed_limit_mbps')||0));
 React.useEffect(()=>{setValue(Number(form.getValues('node_speed_limit_mbps')||0));const sub=form.watch(v=>setValue(Number(v.node_speed_limit_mbps||0)));return()=>sub.unsubscribe()},[form]);
 return h('label',{className:'onenew-field'},h('span',null,'节点总限速（Mbps）'),h('input',{type:'number',min:0,max:100000,step:1,value,onChange:e=>{const n=e.target.value===''?0:Number(e.target.value);setValue(n);form.setValue('node_speed_limit_mbps',n,{shouldDirty:true,shouldValidate:true})}}),h('small',{className:'onenew-help'},'0 为不限速；所有用户共享，上行和下行分别限速。需要新版 caddy。'));
}
