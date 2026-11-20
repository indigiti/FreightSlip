'use client';
import {FormEvent,useEffect,useState} from 'react';
import {ArrowRight,CheckCircle2,Eye,EyeOff,FileSpreadsheet,LockKeyhole,ShieldCheck,Truck,TriangleAlert} from 'lucide-react';
import {BASE,ApiError,api,AuthSession} from '@/lib/api';
import {Button} from '@/components/ui/button';

export default function Login(){
 const [username,setUsername]=useState('');
 const [password,setPassword]=useState('');
 const [visible,setVisible]=useState(false);
 const [loading,setLoading]=useState(false);
 const [checking,setChecking]=useState(true);
 const [message,setMessage]=useState('');
 const [needsSetup,setNeedsSetup]=useState(false);
 useEffect(()=>{let active=true;
  api<AuthSession>('session').then(()=>{if(active)window.location.replace(`${BASE}/`);})
  .catch(e=>{if(active){if(e instanceof ApiError&&e.status===503){setNeedsSetup(true);setMessage(e.message);}setChecking(false);}});
  return()=>{active=false};},[]);
 async function signIn(e:FormEvent<HTMLFormElement>){
  e.preventDefault();setLoading(true);setMessage('');
  try{
   await api<AuthSession>('login',{username,password});
   setPassword('');window.location.replace(`${BASE}/`);
  }catch(e){setMessage(e instanceof Error?e.message:'Could not sign in.');setLoading(false);}
 }
 return <main className="min-h-screen bg-[#f7f8fe] p-4 text-slate-900 sm:p-7 lg:p-10">
  <div className="mx-auto grid min-h-[calc(100vh-3.5rem)] max-w-6xl overflow-hidden rounded-[28px] border border-[#e9e8f4] bg-white shadow-[0_16px_80px_rgba(65,55,131,0.08)] lg:grid-cols-[1.05fr_1fr]">
   <section className="relative hidden overflow-hidden bg-gradient-to-br from-[#5440e7] via-[#4333c7] to-[#241563] px-10 py-12 text-white lg:flex lg:flex-col lg:justify-between">
    <div className="absolute -right-32 -top-32 h-96 w-96 rounded-full border-[60px] border-white/10" aria-hidden="true"/><div className="absolute -bottom-52 -left-40 h-[34rem] w-[34rem] rounded-full border-[80px] border-violet-300/10" aria-hidden="true"/>
    <div className="relative flex items-center gap-3"><span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/15"><Truck size={25}/></span><div><p className="text-lg font-bold tracking-tight">FreightSlip</p><p className="text-xs text-white/75">Shriram Transport</p></div></div>
    <div className="relative max-w-md"><p className="mb-5 text-xs font-bold uppercase tracking-[.24em] text-violet-200">Dispatch workspace</p><h1 className="text-[2.7rem] font-semibold leading-[1.13] tracking-tight">Every lorry receipt. Organised, accurate and ready to print.</h1><p className="mt-6 text-base leading-relaxed text-violet-100">Import Excel dispatch sheets, validate the details, and generate A4 freight receipts without manual re-entry.</p>
    <div className="mt-10 grid gap-4 border-t border-white/20 pt-7"><span className="flex items-center gap-3 text-sm text-white/90"><FileSpreadsheet size={19}/> Intelligent workbook column matching</span><span className="flex items-center gap-3 text-sm text-white/90"><CheckCircle2 size={19}/> Validation before issuing</span><span className="flex items-center gap-3 text-sm text-white/90"><ShieldCheck size={19}/> Private, session-protected records</span></div></div>
    <p className="relative text-xs text-violet-200">FreightSlip · Dispatch management</p>
   </section>
   <section className="flex flex-col justify-center px-5 py-12 sm:px-12 lg:px-16">
    <div className="mx-auto w-full max-w-[420px]">
     <div className="mb-8 flex items-center gap-3 lg:hidden"><span className="rounded-xl bg-violet-100 p-3 text-violet-700"><Truck size={24}/></span><div><p className="font-bold">FreightSlip</p><p className="text-xs text-slate-500">Shriram Transport</p></div></div>
     <div className="mb-7 flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-50 text-violet-700"><LockKeyhole size={23}/></div>
     <h2 className="text-3xl font-bold tracking-tight sm:text-[34px]">Welcome back</h2>
     <p className="mt-2 text-sm leading-relaxed text-slate-500">Sign in to your FreightSlip dispatch workspace.</p>
     <form className="mt-9 space-y-5" onSubmit={signIn}>
      <label className="block text-sm font-semibold text-slate-800">Username<input autoComplete="username" name="username" required autoFocus value={username} onChange={e=>setUsername(e.target.value)} className="mt-2 block h-12 w-full rounded-xl border border-slate-200 bg-[#fcfcff] px-4 font-normal outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-100" placeholder="Enter username"/></label>
      <label className="block text-sm font-semibold text-slate-800">Password<span className="relative mt-2 block"><input name="password" autoComplete="current-password" type={visible?'text':'password'} required value={password} onChange={e=>setPassword(e.target.value)} className="block h-12 w-full rounded-xl border border-slate-200 bg-[#fcfcff] px-4 pr-12 font-normal outline-none transition focus:border-violet-500 focus:ring-2 focus:ring-violet-100" placeholder="Enter password"/><button type="button" aria-label={visible?'Hide password':'Show password'} onClick={()=>setVisible(v=>!v)} className="absolute inset-y-0 right-3 my-auto flex h-9 w-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100">{visible?<EyeOff size={19}/>:<Eye size={19}/>}</button></span></label>
      {message&&<div role="alert" className="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><TriangleAlert size={18} className="mt-0.5 shrink-0"/><div>{message}{needsSetup&&<p className="mt-2"><a className="font-semibold underline" href={`${BASE}/setup/`}>Configure private credentials</a></p>}</div></div>}
      <Button type="submit" className="!mt-7 h-12 w-full rounded-xl bg-violet-600 text-sm font-semibold hover:bg-violet-700" disabled={loading||checking}>{loading?'Signing in…':checking?'Checking session…':<>Sign in <ArrowRight size={17}/></>}</Button>
     </form>
     <div className="mt-8 flex items-start gap-3 rounded-xl bg-[#f7f7fc] px-4 py-3 text-xs leading-relaxed text-slate-500"><ShieldCheck size={19} className="shrink-0 text-violet-600"/><span>Your session is protected. Sign out when using a shared computer.</span></div>
    </div>
   </section>
  </div>
 </main>;
}
