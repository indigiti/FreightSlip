'use client';
import {useState} from 'react';
import {CheckCircle2,Download,LockKeyhole,ShieldCheck,TriangleAlert} from 'lucide-react';

// The setup page does not send credentials to the server or store them in the browser.
// The administrator must upload the generated file through their authenticated host tools.
const ITERATIONS = 600000;
const ROOT = process.env.NEXT_PUBLIC_BASE_PATH || '';
function b64(bytes: Uint8Array): string {
  let out = '';
  for (const byte of bytes) out += String.fromCharCode(byte);
  return btoa(out);
}
export default function PrivateCredentialsSetup(){
  const [username,setUsername]=useState('operator');
  const [password,setPassword]=useState('');
  const [confirm,setConfirm]=useState('');
  const [error,setError]=useState('');
  const [saved,setSaved]=useState(false);
  const [working,setWorking]=useState(false);
  async function generate(e:React.FormEvent<HTMLFormElement>){
    e.preventDefault();setError('');setSaved(false);
    if(!/^[A-Za-z0-9]{3,64}$/.test(username)){setError('Use 3–64 letters and numbers only.');return;}
    if(password.length<14){setError('Use a unique password of at least 14 characters.');return;}
    if(password!==confirm){setError('The passwords do not match.');return;}
    if(!globalThis.crypto?.subtle){setError('Use HTTPS or a secure local browser to generate credentials.');return;}
    setWorking(true);
    try{
      const salt=crypto.getRandomValues(new Uint8Array(16));
      const key=await crypto.subtle.importKey('raw',new TextEncoder().encode(password),'PBKDF2',false,['deriveBits']);
      const digest=await crypto.subtle.deriveBits({name:'PBKDF2',salt,iterations:ITERATIONS,hash:'SHA-256'},key,256);
      const auth={username,pbkdf2_sha256:{iterations:ITERATIONS,salt:b64(salt),hash:b64(new Uint8Array(digest))}};
      const blob=new Blob([JSON.stringify(auth,null,2)+'\n'],{type:'application/json'});
      const objectUrl=URL.createObjectURL(blob);
      const a=document.createElement('a');a.href=objectUrl;a.download='auth.json';document.body.appendChild(a);a.click();a.remove();
      setTimeout(()=>URL.revokeObjectURL(objectUrl),15000);
      setPassword('');setConfirm('');setSaved(true);
    }catch{setError('Credential generation failed. Please use a current browser with Web Crypto support.');}
    finally{setWorking(false);}
  }
  return <main className="min-h-screen bg-[#f6f7fc] px-4 py-10 text-slate-900 sm:py-16">
    <div className="mx-auto max-w-2xl rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-9">
      <a href={`${ROOT}/`} className="text-sm font-semibold text-indigo-700 underline">← Back to FreightSlip</a>
      <div className="mt-6 flex items-center gap-3"><span className="rounded-xl bg-indigo-50 p-3 text-indigo-700"><LockKeyhole size={26}/></span><div><h1 className="text-2xl font-bold">Set up FreightSlip access</h1><p className="text-sm text-slate-600">One-time configuration for the Cloudways private folder</p></div></div>
      <div className="mt-6 flex gap-3 rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm text-blue-950"><ShieldCheck size={20} className="shrink-0"/><p>Your password is processed entirely in this browser. It is never transmitted to FreightSlip, GitHub or ChatGPT. Only a salted password hash is saved in the downloaded file.</p></div>
      <form onSubmit={generate} className="mt-7 grid gap-4">
        <label className="grid gap-1.5 text-sm font-semibold">Administrator username<input type="text" autoComplete="username" required value={username} maxLength={64} onChange={e=>setUsername(e.target.value)} className="rounded-lg border border-slate-300 p-3"/></label>
        <label className="grid gap-1.5 text-sm font-semibold">New password<input type="password" autoComplete="new-password" required minLength={14} value={password} onChange={e=>setPassword(e.target.value)} className="rounded-lg border border-slate-300 p-3"/></label>
        <label className="grid gap-1.5 text-sm font-semibold">Confirm password<input type="password" autoComplete="new-password" required value={confirm} onChange={e=>setConfirm(e.target.value)} className="rounded-lg border border-slate-300 p-3"/></label>
        {error&&<p role="alert" className="flex items-center gap-2 text-sm text-red-700"><TriangleAlert size={18}/>{error}</p>}
        <button disabled={working} type="submit" className="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 p-3 font-semibold text-white disabled:opacity-60"><Download size={18}/>{working?'Generating…':'Download private auth.json'}</button>
      </form>
      {saved&&<p role="status" className="mt-4 flex items-center gap-2 text-sm text-green-800"><CheckCircle2 size={18}/>The private credentials file was downloaded. It has NOT been installed on Cloudways yet.</p>}
      <section className="mt-8 border-t border-slate-200 pt-6"><h2 className="text-lg font-bold">Finish setup in Cloudways</h2>
        <ol className="mt-3 list-decimal space-y-3 pl-5 text-sm leading-relaxed text-slate-700">
          <li>Use your Cloudways application's secure file manager, or an SFTP desktop client, to open the private application directory.</li>
          <li>Place the downloaded <code className="rounded bg-slate-100 px-1">auth.json</code> at <code className="break-all rounded bg-slate-100 px-1">private_html/freightslip/storage/auth.json</code>. Create <code>storage/</code> if necessary; it must be writable by PHP. Do not place this file in <code>public_html/</code>.</li>
          <li>Open <a className="text-indigo-700 underline" href={`${ROOT}/api/index.php?action=health`}>Check API health</a>. It should return <code>{'{"status":"ok"}'}</code>.</li>
          <li>Open the <a className="text-indigo-700 underline" href={`${ROOT}/api/index.php`}>sign-in endpoint</a>, enter the username and password chosen above, then reload the dashboard.</li>
        </ol>
        <p className="mt-4 text-xs text-slate-600">Keep a secure copy of the credentials. The downloaded file contains a hash, not a recoverable password. A new file replaces the previous administrator login. Do not commit this file or attach it to a support ticket.</p>
      </section>
    </div>
  </main>;
}
