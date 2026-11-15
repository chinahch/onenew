export function energy(value:number|string|undefined):string{const cents=Number(value??0);return Number.isSafeInteger(cents)?`${(cents/100).toFixed(2)} 能量`:'—'}
export function energyAmount(value:string):string{if(!/^(0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?$/.test(value))throw new Error('请输入正数能量，最多两位小数');const [whole,part='']=value.split('.');const cents=Number(whole)*100+Number(part.padEnd(2,'0'));if(cents<1||cents>100000000)throw new Error('补充范围为 0.01–1000000 能量');return value}
export function energyName(value:string|undefined):string{return (value||'').replace(/(\d+(?:\.\d+)?)\s*元/g,'$1能量').replace(/[¥￥]/g,'能量 ')}
export function requestKey():string{const b=new Uint8Array(16);crypto.getRandomValues(b);return Array.from(b,v=>v.toString(16).padStart(2,'0')).join('')}
