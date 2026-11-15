export function initialPublicView(hash:string,search:string):'home'|'login'{return hash==='#login'||!!new URLSearchParams(search).get('invite')?'login':'home'}
