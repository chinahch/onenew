export function connectionState(heartbeat:string|null|undefined,now=Date.now()):'pending'|'online'|'offline'{
 const at=heartbeat?Date.parse(heartbeat):NaN
 return !Number.isFinite(at)?'pending':now-at<=45000&&now-at>=-30000?'online':'offline'
}
