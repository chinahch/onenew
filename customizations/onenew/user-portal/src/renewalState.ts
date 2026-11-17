export function reconcileRenewalKey(key:string,rows:{request_id:string;status?:string}[]):string{
 return rows.some(row=>row.request_id===key)?'':key
}
export function renewalMessage(response:{status:string}):string{
 if(response.status==='approved')return '能量已抵扣，续期已生效'
 if(response.status==='processing')return '续期正在开通'
 if(response.status==='pending')return '续期订单已提交，剩余能量需补充或由管理员确认'
 throw new Error('此申请已处理，请刷新订单状态')
}
