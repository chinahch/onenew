const key='onenew.auth'
let bearer=sessionStorage.getItem(key)||''
let generation=0
export function session(){return {bearer,generation}}
export function setSession(value:string){
 if(typeof value!=='string'||(value&&!/^Bearer [A-Za-z0-9|._-]+$/.test(value)))throw new Error('登录响应缺少有效凭据')
 generation++;bearer=value
 if(value)sessionStorage.setItem(key,value);else sessionStorage.removeItem(key)
 window.dispatchEvent(new Event('onenew-session'))
}
