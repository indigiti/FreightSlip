// Thin browser transport. Domain processing remains in PHP; no Next.js server is required.
export const BASE=process.env.NEXT_PUBLIC_BASE_PATH || '';
const endpoint=process.env.NEXT_PUBLIC_LR_API || `${BASE}/api/index.php`;
export type AuthSession={authenticated:true;username:string;csrf:string};
let csrf='';
export class ApiError extends Error {
 constructor(message:string,public status:number){super(message);this.name='ApiError';}
}
export function url(action:string,params:Record<string,string>={}){
 return `${endpoint}?${new URLSearchParams({action,...params})}`;
}
export function clearAuth(){csrf='';}
export async function api<T>(action:string,body?:object):Promise<T>{
 const isPost=body!==undefined;
 const upload=body instanceof FormData;
 const r=await fetch(url(action),{
  credentials:'same-origin',cache:'no-store',
  ...(isPost?{method:'POST',headers:{'X-LR-Studio':'1',
   ...(csrf&&action!=='login'?{'X-LR-CSRF':csrf}:{}),
   ...(upload?{}:{'Content-Type':'application/json'})},
   body:upload?body:JSON.stringify(body)}:{}),
 });
 const v=await r.json().catch(()=>null);
 if(!r.ok||!v){
  if(r.status===401&&action!=='login'){
   clearAuth();
   if(typeof window!=='undefined' && !window.location.pathname.startsWith(`${BASE}/login`))
    window.location.assign(`${BASE}/login/`);
  }
  throw new ApiError(v?.error||`The PHP API did not return a valid response (${r.status}).`,r.status);
 }
 if((action==='session'||action==='login')&&v.csrf)csrf=v.csrf;
 if(action==='logout')clearAuth();
 return v as T;
}
