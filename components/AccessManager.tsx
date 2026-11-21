'use client';

import {FormEvent,useEffect,useState} from 'react';
import {CheckCircle2,KeyRound,ShieldCheck,Trash2,UserPlus,Users,XCircle} from 'lucide-react';
import {Button} from '@/components/ui/button';
import {Input} from '@/components/ui/input';
import {api} from '@/lib/api';

type Workspace={id:string;name:string;sheet:string;count:number};
type ManagedUser={username:string;role:'download';enabled:boolean;workspaces:string[];created_at:string;updated_at:string};
type UsersResponse={admin:string;users:ManagedUser[]};
type Edit={enabled:boolean;workspaces:string[];password:string};

export function AccessManager({workspaces}:{workspaces:Workspace[]}){
 const [accounts,setAccounts]=useState<ManagedUser[]>([]);
 const [primary,setPrimary]=useState('');
 const [username,setUsername]=useState('');
 const [password,setPassword]=useState('');
 const [selected,setSelected]=useState<string[]>([]);
 const [edits,setEdits]=useState<Record<string,Edit>>({});
 const [busy,setBusy]=useState(false);
 const [loading,setLoading]=useState(true);
 const [message,setMessage]=useState('');
 const [error,setError]=useState('');
 const load=async()=>{
  const res=await api<UsersResponse>('users');
  setAccounts(res.users);setPrimary(res.admin);
  setEdits(old=>Object.fromEntries(res.users.map(u=>[u.username,{
    enabled:u.enabled,workspaces:u.workspaces,password:old[u.username]?.password??''
  }])));
 };
 useEffect(()=>{
  let live=true;
  api<UsersResponse>('users').then(res=>{if(!live)return;
   setAccounts(res.users);setPrimary(res.admin);
   setEdits(Object.fromEntries(res.users.map(u=>[u.username,{
    enabled:u.enabled,workspaces:u.workspaces,password:''
   }])));
  }).catch(e=>{if(live)setError(e instanceof Error?e.message:'Could not load users.');})
   .finally(()=>{if(live)setLoading(false);});
  return()=>{live=false;};
 },[]);
 async function act(fn:()=>Promise<unknown>,notice:string){
  setBusy(true);setError('');setMessage('');
  try{await fn();setMessage(notice);await load();}
  catch(e){setError(e instanceof Error?e.message:'Could not update this account.');}
  finally{setBusy(false);}
 }
 function toggle(ids:string[],id:string,write:(next:string[])=>void){
  write(ids.includes(id)?ids.filter(v=>v!==id):[...ids,id]);
 }
 function update(name:string,patch:Partial<Edit>){
  setEdits(old=>({...old,[name]:{...old[name],...patch}}));
 }
 function create(e:FormEvent<HTMLFormElement>){
  e.preventDefault();
  void act(async()=>{
   await api('user_create',{username:username.trim(),password,workspaces:selected,enabled:true});
   setUsername('');setPassword('');setSelected([]);
  },'Download-only account created. Only assigned workspaces are visible to this user.');
 }
 function save(user:ManagedUser){
  const d=edits[user.username];if(!d)return;
  void act(async()=>{
   await api('user_update',{username:user.username,enabled:d.enabled,
    workspaces:d.workspaces,...(d.password?{password:d.password}:{})});
   setEdits(old=>({...old,[user.username]:{...d,password:''}}));
  },`Updated ${user.username}. Revoked access takes effect on the next request.`);
 }
 function remove(user:ManagedUser){
  if(!window.confirm(`Delete account “${user.username}”? The user will immediately lose access. This does not delete LR workspaces.`))return;
  void act(()=>api('user_delete',{username:user.username}),`Deleted account ${user.username}.`);
 }
 return <section className="space-y-5" aria-label="Users and workspace access">
  <div className="flex items-center justify-between gap-3"><div>
   <h2 className="text-xl font-bold text-slate-900">Users &amp; Access</h2>
   <p className="mt-1 text-sm text-slate-500">Create download-only accounts and grant access to selected workspaces.</p>
  </div><Users size={24} className="text-violet-600" aria-hidden="true"/></div>
  {error&&<p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{error}</p>}
  {message&&<p role="status" className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800"><CheckCircle2 size={17}/>{message}</p>}
  <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]">
   <form onSubmit={create} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h3 className="flex items-center gap-2 font-bold"><UserPlus size={18} className="text-violet-600"/>Add a download-only user</h3>
    <p className="mt-1 text-xs leading-relaxed text-slate-500">New accounts cannot upload Excel files, issue receipts, change office settings, or manage users. Access starts empty.</p>
    <div className="mt-4 grid gap-3 sm:grid-cols-2">
     <label className="grid gap-1 text-sm font-semibold">Username
      <Input required autoComplete="off" minLength={3} maxLength={64} pattern="[A-Za-z0-9]{3,64}" placeholder="e.g. dispatch01" value={username} onChange={e=>setUsername(e.target.value)}/>
     </label>
     <label className="grid gap-1 text-sm font-semibold">Temporary password
      <Input required type="password" autoComplete="new-password" minLength={14} maxLength={256} placeholder="Minimum 14 characters" value={password} onChange={e=>setPassword(e.target.value)}/>
     </label>
    </div>
    <fieldset className="mt-4 rounded-xl border border-slate-200 p-3">
     <legend className="px-1 text-sm font-semibold">Allow downloads from</legend>
     <div className="mt-1 max-h-44 space-y-2 overflow-auto">
      {workspaces.map(w=><label key={w.id} className="flex cursor-pointer items-start gap-2 text-sm">
       <input type="checkbox" className="mt-1 accent-violet-600" checked={selected.includes(w.id)} onChange={()=>toggle(selected,w.id,setSelected)}/>
       <span className="min-w-0"><span className="block break-all font-medium">{w.name}</span><span className="text-xs text-slate-500">{w.count} LRs{w.sheet?` · ${w.sheet}`:''}</span></span>
      </label>)}
      {!workspaces.length&&<p className="text-xs text-slate-500">No active workspaces yet. You can create a user with no access.</p>}
     </div>
    </fieldset>
    <Button className="mt-4" type="submit" disabled={busy||loading}><UserPlus size={16}/> Create user</Button>
   </form>
   <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h3 className="font-bold">Accounts</h3>
    <p className="mt-1 text-xs text-slate-500">The original administrator stays in private auth.json. Managed account passwords are stored as secure hashes.</p>
    <div className="my-4 flex items-center justify-between gap-2 rounded-lg bg-violet-50 p-3 text-sm">
     <span className="flex min-w-0 items-center gap-2"><ShieldCheck className="shrink-0 text-violet-600" size={18}/><span className="truncate font-semibold">{primary||'Primary administrator'}</span></span>
     <span className="text-xs font-semibold text-violet-700">Admin · All workspaces</span>
    </div>
    {loading?<p role="status" className="text-sm text-slate-500">Loading users…</p>:null}
    <div className="max-h-[38rem] space-y-3 overflow-y-auto">
     {accounts.map(u=>{
      const d=edits[u.username]??{enabled:u.enabled,workspaces:u.workspaces,password:''};
      return <details key={u.username} className="rounded-xl border border-slate-200 p-3">
       <summary className="cursor-pointer text-sm font-semibold"><span>{u.username}</span>
        <span className={`ml-2 rounded-full px-2 py-0.5 text-xs ${u.enabled?'bg-emerald-50 text-emerald-700':'bg-slate-100 text-slate-500'}`}>{u.enabled?'Enabled':'Disabled'}</span>
        <span className="ml-2 text-xs font-normal text-slate-500">{u.workspaces.length} workspaces</span>
       </summary>
       <div className="mt-4 space-y-3">
        <label className="flex items-center gap-2 text-sm font-medium"><input className="accent-violet-600" type="checkbox" checked={d.enabled} onChange={e=>update(u.username,{enabled:e.target.checked})}/> Account enabled</label>
        <fieldset className="rounded-lg border border-slate-200 p-3">
         <legend className="px-1 text-sm font-medium">Allowed workspaces</legend>
         <div className="max-h-40 space-y-2 overflow-auto">
          {workspaces.map(w=><label key={w.id} className="flex cursor-pointer items-start gap-2 text-sm">
           <input type="checkbox" className="mt-1 accent-violet-600" checked={d.workspaces.includes(w.id)} onChange={()=>toggle(d.workspaces,w.id,ids=>update(u.username,{workspaces:ids}))}/>
           <span className="min-w-0 break-all">{w.name} <span className="text-xs text-slate-500">({w.count} LRs)</span></span>
          </label>)}
          {!workspaces.length&&<p className="text-xs text-slate-500">No active workspaces.</p>}
         </div>
        </fieldset>
        <label className="grid gap-1 text-sm font-medium"><span className="flex items-center gap-2"><KeyRound size={15}/> Reset password (optional)</span>
         <Input type="password" autoComplete="new-password" placeholder="Leave blank to keep current password" minLength={14} maxLength={256} value={d.password} onChange={e=>update(u.username,{password:e.target.value})}/>
        </label>
        <div className="flex flex-wrap gap-2"><Button type="button" disabled={busy} onClick={()=>save(u)}><CheckCircle2 size={16}/> Save permissions</Button>
         <Button type="button" variant="outline" disabled={busy} onClick={()=>remove(u)}><Trash2 size={16}/> Delete account</Button></div>
       </div>
      </details>;
     })}
     {!loading&&!accounts.length&&<p className="text-sm text-slate-500">No download-only users created yet.</p>}
    </div>
   </div>
  </div>
  <div className="flex items-start gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600"><XCircle size={17} className="shrink-0"/>
   Removing a workspace grant prevents new register, preview, PDF and details requests immediately. It does not remotely erase PDFs already downloaded by a user.
  </div>
 </section>;
}
