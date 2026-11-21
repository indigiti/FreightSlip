'use client';
import {useEffect,useRef,useState} from 'react';
import {ArrowDownToLine,ArrowRight,CheckCircle2,ChevronRight,ClipboardList,CloudUpload,Download,FileCheck2,FileSpreadsheet,FileText,LayoutGrid,Layers3,MapPin,Printer,Search,Sparkles,Truck,TriangleAlert,LogOut,Settings2,Save,Trash2,RotateCcw,FolderOpen,ArchiveRestore,X,UsersRound,QrCode} from 'lucide-react';
import {Button} from '@/components/ui/button';
import {Input} from '@/components/ui/input';
import {BASE,api,url,clearAuth,AuthSession} from '@/lib/api';
import {PDF_BATCH_SIZE,exportPdfBatchesZip,ExportProgress} from '@/lib/batch-export';
import {AccessManager} from '@/components/AccessManager';
import {PrintDesignEditor} from '@/components/PrintDesignEditor';
import {VisualPrintDesigner} from '@/components/VisualPrintDesigner';

type Mapping=Record<string,number>;
type Upload={upload:string;file:string;sheet:string;sheets:string[];inferred:string[];header:number;headers:string[];rows:number;map:Mapping;fields:Record<string,string>;required:string[];assistant:boolean};
type Workspace={id:string;name:string;sheet:string;count:number;last_issued:string;legacy:boolean};
type DeletedWorkspace=Workspace & {deleted_at:string;expires_at:string};
type RegisterResponse={groups:Group[];workspaces:Workspace[];recycle:DeletedWorkspace[]};
type VisualMeta={id:string;name:string;version:number};
type Group={workspace_id?:string;source_file?:string;id:string;key:string;status:'ready'|'blocked'|'issued';rows:number[];issues:string[];items:unknown[];issued_at?:string;common:{consignee:string;destination:string;date:string|null}};
type Review={review:string;groups:Group[]};
type Suggestion={field:string;column:number;score:number};
type OfficeSettings={consignor_address:string;carrier:string;address:string};
type OfficeSettingsResponse={settings:OfficeSettings;version:number};
const labels={ready:'Ready',blocked:'Needs review',issued:'Issued'};
const statusClasses={ready:'bg-emerald-50 text-emerald-800',blocked:'bg-amber-50 text-amber-900',issued:'bg-slate-100 text-slate-700'};
type PrintVariant='classic'|'clean';
function LayoutMini({variant,includeQr}:{variant:PrintVariant;includeQr:boolean}){
 return <span className={`layout-mini layout-mini--${variant}`} aria-hidden="true">
 {[0,1].map(i=><span className="layout-mini-half" key={i}>
  <span className="layout-mini-header">SHRIRAM TRANSPORT</span>
  <span className="layout-mini-address"/>
  <span className="layout-mini-identity"><i/><i/></span>
  <span className="layout-mini-rule"/>
  <span className="layout-mini-grid"><span/><span className="layout-mini-particulars"><i/><i/><i/></span><span className="layout-mini-charges"><i/><i/><i/></span></span>
  {includeQr&&<span className="layout-mini-qr"/>}
  <span className="layout-mini-signature"/>
 </span>)}
 </span>;
}
export default function Home(){
 const [tab,setTab]=useState<'import'|'register'|'settings'|'recycle'|'users'|'print_design'|'visual_designer'>('register'),[upload,setUpload]=useState<Upload|null>(null),[map,setMap]=useState<Mapping>({}),[review,setReview]=useState<Review|null>(null),[records,setRecords]=useState<Group[]>([]),[workspaces,setWorkspaces]=useState<Workspace[]>([]),[recycle,setRecycle]=useState<DeletedWorkspace[]>([]),[workspaceFilter,setWorkspaceFilter]=useState('all'),[selection,setSelection]=useState<string[]>([]),[query,setQuery]=useState(''),[preview,setPreview]=useState<{name:string;url:string}|null>(null);
 const [date,setDate]=useState(''),[override,setOverride]=useState(''),[freight,setFreight]=useState(''),[allowZero,setAllowZero]=useState(false),[header,setHeader]=useState(''),[file,setFile]=useState<File|null>(null),[suggestions,setSuggestions]=useState<Suggestion[]>([]);
 const [printLayout,setPrintLayout]=useState<string>('classic');
 const [visualTemplates,setVisualTemplates]=useState<VisualMeta[]>([]);
 const [printQR,setPrintQR]=useState(true);
 const [officeSettings,setOfficeSettings]=useState<OfficeSettings|null>(null),[settingsVersion,setSettingsVersion]=useState(0);
 const [currentUser,setCurrentUser]=useState('');const [role,setRole]=useState<'admin'|'download'>('download');const [authReady,setAuthReady]=useState(false);
 const [filter,setFilter]=useState<'all'|'selected'>('all');
 const isAdmin=role==='admin';
 const [exportProgress,setExportProgress]=useState<ExportProgress|null>(null);
 const exportAbort=useRef<AbortController|null>(null);
 const exporting=exportProgress!==null;
 const [error,setError]=useState(''),[note,setNote]=useState(''),[busy,setBusy]=useState(false),[drag,setDrag]=useState(false);const picker=useRef<HTMLInputElement>(null),dialog=useRef<HTMLDialogElement>(null);
 useEffect(()=>{if(preview&&!dialog.current?.open)dialog.current?.showModal();},[preview]);
 const invalidate=()=>{setReview(null);setSelection([]);};
 const toggle=(k:string)=>{
  if(exporting)return;
  setSelection(old=>old.includes(k)?old.filter(x=>x!==k):tab==='register'||old.length<100?[...old,k]:old);
 };
 const run=async(fn:()=>Promise<unknown>)=>{setBusy(true);setError('');setNote('');try{await fn();}catch(e){setError(e instanceof Error?e.message:'Unexpected error.');}finally{setBusy(false);}};
 const loadRegister=async()=>{
  const data=await api<RegisterResponse>('register');
  setRecords(data.groups);setWorkspaces(data.workspaces);setRecycle(data.recycle);
  setWorkspaceFilter(old=>old&&data.workspaces.some(w=>w.id===old)?old:data.workspaces[0]?.id||'all');
  return data;
 };
 const changeWorkspace=(id:string)=>{setWorkspaceFilter(id);setSelection([]);setFilter('all');setQuery('');setNote('');};
 const currentWorkspace=()=>workspaces.find(w=>w.id===workspaceFilter);
 const openRecycle=()=>{setTab('recycle');setSelection([]);setError('');setNote('');void run(loadRegister);};
 const trashWorkspace=()=>{const ws=currentWorkspace();if(!ws||busy)return;
   if(!window.confirm(`Move workspace “${ws.name}” and all ${ws.count} issued LRs to Recycle Bin? They will be hidden immediately and permanently removed after 30 days.`))return;
   if(ws.legacy&&!window.confirm('This older workspace is grouped by filename, not its original import. Multiple older uploads using that filename may be combined. Continue?'))return;
   void run(async()=>{await api('workspace_trash',{id:ws.id});setSelection([]);setPreview(null);await loadRegister();setNote(`Moved “${ws.name}” to Recycle Bin. You can restore it within 30 days.`);});
 };
 const restoreWorkspace=(ws:DeletedWorkspace)=>{if(busy)return;void run(async()=>{await api('workspace_restore',{id:ws.id});await loadRegister();setTab('register');setWorkspaceFilter(ws.id);setSelection([]);setNote(`Restored “${ws.name}” and ${ws.count} LRs.`);});};
 const purgeWorkspace=(ws:DeletedWorkspace)=>{if(busy||window.prompt(`Permanently delete “${ws.name}” and its ${ws.count} LRs? This cannot be undone. Type DELETE to confirm.`)!=='DELETE')return;
   void run(async()=>{await api('workspace_purge',{id:ws.id});await loadRegister();setNote(`Permanently deleted “${ws.name}”.`);});
 };
 const emptyRecycle=()=>{if(busy||!recycle.length||window.prompt(`Permanently delete all ${recycle.length} workspaces in Recycle Bin? This cannot be undone. Type EMPTY to confirm.`)!=='EMPTY')return;
   void run(async()=>{await api('recycle_empty',{});await loadRegister();setNote('Recycle Bin emptied. Workspace LR data was permanently deleted.');});
 };
 const selectAllMatching=(matching:Group[])=>{
  if(exporting)return;
  const keys=matching.map(g=>g.key);
  setSelection(keys);setFilter('all');
  setNote(keys.length>PDF_BATCH_SIZE?`Selected all ${keys.length} LRs. Download as ${Math.ceil(keys.length/PDF_BATCH_SIZE)} PDFs, with up to 100 LRs per file.`:`Selected ${keys.length} LRs for A4 PDF export.`);
 };
 async function downloadSelectedBatches(){
  if(exportAbort.current||busy||chosen.length<=PDF_BATCH_SIZE)return;
  const controller=new AbortController();
  exportAbort.current=controller;
  const keys=[...chosen],layout=printLayout,includeQr=printQR;
  const total=Math.ceil(keys.length/PDF_BATCH_SIZE);
  setExportProgress({completed:0,total,receipts:0});
  setError('');setNote('');
  try{
   const zip=await exportPdfBatchesZip(keys,layout,includeQr,{
    signal:controller.signal,onProgress:setExportProgress
   });
   if(controller.signal.aborted)return;
   const href=URL.createObjectURL(zip);
   const link=document.createElement('a');
   link.href=href;link.download=`FreightSlip-${keys.length}-LRs-${new Date().toISOString().slice(0,10)}.zip`;
   document.body.appendChild(link);link.click();link.remove();
   window.setTimeout(()=>URL.revokeObjectURL(href),60000);
   setNote(`Generated ${total} PDFs for ${keys.length} LRs (up to 100 in each). ZIP download started.`);
  }catch(e){
   if(e instanceof Error&&e.name==='AbortError')setNote('Bulk export canceled. No incomplete ZIP was downloaded.');
   else setError(e instanceof Error?e.message:'Bulk PDF export failed. No incomplete ZIP was downloaded.');
  }finally{
   exportAbort.current=null;
   setExportProgress(null);
  }
 }

 const openRegister=()=>{setSelection([]);setQuery('');setFilter('all');setWorkspaceFilter('');setTab('register');void run(loadRegister);};
 const openImport=()=>{setTab('import');setSelection([]);setError('');setNote('');};
 const openSettings=()=>{setTab('settings');setSelection([]);void run(async()=>{const data=await api<OfficeSettingsResponse>('settings');setOfficeSettings(data.settings);setSettingsVersion(data.version);});};
 const saveOfficeSettings=()=>{if(!officeSettings)return;void run(async()=>{const data=await api<OfficeSettingsResponse>('settings',{settings:officeSettings,version:settingsVersion});setOfficeSettings(data.settings);setSettingsVersion(data.version);invalidate();setNote('Office details saved. New LR reviews will use these details. Issued receipts remain unchanged.');});};
 useEffect(()=>{let live=true;setBusy(true);api<AuthSession>('session').then(async info=>{if(!live)return;setCurrentUser(info.username);setRole(info.role);setPrintLayout(info.print_defaults.layout);setPrintQR(info.print_defaults.qr);const [data,templates]=await Promise.all([api<RegisterResponse>('register'),api<{templates:VisualMeta[]}>('visual_public')]);if(live){setVisualTemplates(templates.templates);setRecords(data.groups);setWorkspaces(data.workspaces);setRecycle(data.recycle);setWorkspaceFilter(data.workspaces[0]?.id||'all');setAuthReady(true);}}).catch(e=>{if(live && !(e instanceof Error && e.message.includes('Sign in')))setError(e instanceof Error?e.message:'Could not load the register.');}).finally(()=>{if(live)setBusy(false);});return()=>{live=false;};},[]);
 async function signOut(){try{await api('logout',{});}finally{clearAuth();window.location.assign(`${BASE}/login/`);}}
 async function readWorkbook(source:File, row='', worksheet=''){
  setUpload(null);setMap({});setSuggestions([]);invalidate();
  await run(async()=>{const fd=new FormData();fd.append('workbook',source);fd.append('header',row);fd.append('sheet',worksheet);const u=await api<Upload>('upload',fd);setUpload(u);setMap(u.map);setDate(u.map.lr_date!==undefined?'lr_date':'');setOverride('');setFreight('');setAllowZero(false);});
 }
 function chooseWorkbook(source:File){setFile(source);setHeader('');void readWorkbook(source);}
 function changeField(k:string,v:string){setMap(m=>{const next={...m};if(v==='')delete next[k];else next[k]=Number(v);return next;});invalidate();}
 const matching=records.filter(g=>(workspaceFilter==='all'||g.workspace_id===workspaceFilter) && [g.id,g.common.consignee,g.common.destination].some(v=>String(v??'').toLowerCase().includes(query.trim().toLowerCase())));
 const shown=tab==='register'?matching.filter(g=>filter==='all'||selection.includes(g.key)):tab==='import'?review?.groups||[]:[];
 const selectedSet=new Set(selection);
 const allMatchingSelected=matching.length>0&&matching.every(g=>selectedSet.has(g.key));
 const chosen=tab==='register'?selection:selection.filter(k=>review?.groups.some(g=>g.key===k&&g.status==='ready'));
 function show(g:Group){setPreview({name:g.id,url:url('preview',{layout:printLayout,qr:printQR?'1':'0',keys:g.key,...(tab==='import'&&review?{review:review.review}:{})})});}
 const stats=[
  {title:'Issued receipts',value:records.length,detail:'Saved in the LR register',icon:FileCheck2,tone:'violet'},
  {title:'Destinations',value:new Set(records.map(g=>g.common.destination.trim().toUpperCase()).filter(Boolean)).size,detail:'Across issued receipts',icon:MapPin,tone:'blue'},
  {title:'Selected LRs',value:chosen.length,detail:'Ready for batch preview',icon:Layers3,tone:'green'},
  {title:'A4 sheets',value:Math.ceil(chosen.length/2),detail:'Two different LRs per sheet',icon:Printer,tone:'amber'},
 ];
 if(!authReady)return <main className="flex min-h-screen items-center justify-center bg-[#f7f8fc] p-6"><div role="status" className="rounded-2xl border border-slate-200 bg-white px-8 py-7 text-center text-slate-600 shadow-sm">{error?<><p className="font-semibold text-red-700">{error}</p>{error.includes('auth.json')&&<a href={`${BASE}/setup/`} className="mt-3 block text-violet-700 underline">Set up FreightSlip access</a>}</>:"Checking secure workspace…"}</div></main>;
 return <><div className="studio-shell">
 <aside className="studio-sidebar"><div className="brand"><span className="brand-icon"><Truck size={23}/></span><div><strong>LR Studio</strong><small>Shriram Transport</small></div></div>
 <nav aria-label="Dispatch workspace" className="studio-nav"><span className="nav-caption">WORKSPACE</span>
  <button type="button" className={tab==='register'?'active':''} aria-current={tab==='register'?'page':undefined} disabled={busy} onClick={openRegister}><FolderOpen size={18}/> Workspaces</button>
  {isAdmin&&<>
   <button type="button" className={tab==='import'?'active':''} disabled={busy} onClick={openImport}><FileSpreadsheet size={18}/> Import Workbook</button>
   <button type="button" className={tab==='settings'?'active':''} disabled={busy} onClick={openSettings}><Settings2 size={18}/> Office Settings</button>
   <button type="button" className={tab==='print_design'?'active':''} disabled={busy} onClick={()=>{setTab('print_design');setSelection([]);setError('');setNote('');}}><Printer size={18}/> Print Designs</button>
   <button type="button" className={tab==='visual_designer'?'active':''} disabled={busy} onClick={()=>{setTab('visual_designer');setSelection([]);setError('');setNote('');}}><Layers3 size={18}/> Visual Designer</button>
   <button type="button" className={tab==='users'?'active':''} disabled={busy} onClick={()=>{setTab('users');setSelection([]);setError('');setNote('');}}><UsersRound size={18}/> Users &amp; Access</button>
   <button type="button" className={tab==='recycle'?'active':''} disabled={busy} onClick={openRecycle}><Trash2 size={18}/> Recycle Bin {recycle.length>0?<span className="ml-auto rounded-full bg-violet-100 px-2 text-xs font-bold text-violet-700">{recycle.length}</span>:null}</button>
  </>}
 </nav>
 <div className="sidebar-footer"><span className="studio-icon violet"><Truck size={17}/></span><div><strong>Shriram Transport</strong><small>{currentUser}</small></div><button type="button" title="Sign out" aria-label="Sign out" onClick={()=>void signOut()} className="ml-auto rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-violet-700"><LogOut size={19}/></button></div></aside>
 <div className="studio-workspace"><header className="studio-topbar"><div className="crumb"><LayoutGrid size={17}/><span>Dispatch workspace</span><ChevronRight size={14}/><strong>{tab==='register'?'Workspaces':tab==='import'?'Import Workbook':tab==='recycle'?'Recycle Bin':tab==='users'?'Users & Access':tab==='print_design'?'Print Designs':tab==='visual_designer'?'Visual Designer':'Office Settings'}</strong></div><span className="top-format"><Printer size={15}/> A4 portrait</span></header>
 <main className="studio-main"><section className="studio-heading"><div><p className="eyebrow">DISPATCH DOCUMENTS</p><h1>{tab==='register'?'Workspaces & LR Register':tab==='import'?'Import Workbook':tab==='recycle'?'Recycle Bin':tab==='users'?'Users & Access':tab==='print_design'?'Print Designs':tab==='visual_designer'?'Visual Designer':'Office Settings'}</h1><p>{tab==='register'?'Keep each Excel import separate, select its LRs, then print two per A4.':tab==='import'?'Turn dispatch spreadsheets into printable LRs. Verify dates and charges before issuing.':tab==='recycle'?'Restore a removed workspace within 30 days, or permanently erase it.':tab==='users'?'Create download-only accounts and manage access to each workspace.':tab==='print_design'?'Customize Classic and Print Clean without changing LR data or A4 geometry.':tab==='visual_designer'?'Move and resize LR fields, edit typography, proof the PDF, then publish named A4 templates.':'Manage the transporter and consignor address printed on new LRs.'}</p></div><div className="heading-actions">{isAdmin?(tab==='register'?<Button disabled={busy} onClick={openImport}><CloudUpload size={17}/> Import workbook</Button>:<Button variant="outline" disabled={busy} onClick={openRegister}><ClipboardList size={17}/> View register</Button>):null}</div></section>
 {error&&<div role="alert" className="mt-4 flex gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-900"><TriangleAlert size={18} className="shrink-0"/><div><p>{error}</p>{error.includes('auth.json')&&<p className="mt-2"><a className="font-semibold underline" href={`${BASE}/setup/`}>Create credentials using the secure setup guide</a><span className="ml-2 text-xs">No terminal required · Requires access to Cloudways private files</span></p>}</div></div>}
 {note&&<p role="status" className="mt-4 flex gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm"><CheckCircle2 size={18} className="shrink-0"/>{note}</p>}
 {busy&&<p role="status" className="mt-4 text-sm text-slate-600">Processing…</p>}
 {tab==='register'&&<>
 <section className="mb-4 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-violet-100 bg-white px-4 py-4 sm:px-6" aria-label="Workspace manager">
   <div><div className="flex items-center gap-2"><FolderOpen size={19} className="text-violet-600"/><h2 className="text-base font-bold">Your workspaces</h2></div><p className="mt-1 text-xs text-slate-500">{workspaces.length} {workspaces.length===1?'workspace':'workspaces'} · Each issued Excel import is kept separate</p></div>
   <div className="flex max-w-full flex-wrap items-end gap-3">
    <label className="grid gap-1 text-xs font-semibold text-slate-600">Current workspace<select className="max-w-[min(80vw,480px)]" aria-label="Select workspace" value={workspaceFilter} onChange={e=>changeWorkspace(e.target.value)}>
      {workspaces.length===0?<option value="all">No issued workspaces yet</option>:null}
      {workspaces.map(ws=><option key={ws.id} value={ws.id}>{ws.name}{ws.sheet&&ws.sheet!=='CSV'?` · ${ws.sheet}`:''} · {ws.count} LRs{ws.legacy?' (older import)':''}</option>)}
      {workspaces.length>0?<option value="all">All workspaces · {records.length} LRs</option>:null}
    </select></label>
    <Button variant="outline" disabled={busy||!currentWorkspace()} onClick={trashWorkspace} className="border-red-200 text-red-700 hover:bg-red-50"><Trash2 size={16}/> Remove workspace</Button>
   </div>
   {currentWorkspace()?.legacy?<p className="w-full text-xs text-amber-700">Older workspaces are grouped by filename because historical upload IDs were unavailable. Check their contents before removal.</p>:null}
   {workspaceFilter==='all'&&workspaces.length>0?<p className="w-full text-xs text-amber-700">All workspaces selected for browsing. Use Select all only if you intentionally want to combine LR PDFs across workspaces.</p>:null}
 </section><div className="stats-grid">{stats.map(({title,value,detail,icon:Icon,tone})=><div className="stat-card" key={title}><div className="stat-label"><span className={`studio-icon ${tone}`}><Icon size={17}/></span>{title}</div><strong className="stat-value">{busy&&value===0?'—':value.toLocaleString('en-IN')}</strong><p>{detail}</p></div>)}</div>
 <div className="register-panels"><section className="studio-panel batch-print-panel">
 <div className="panel-heading"><div><h2>Batch print</h2><p>Select issued receipts, choose a design, preview and export.</p></div><span className="format-badge">A4 PORTRAIT</span></div>
 <div className="print-flow"><div><span>01</span><div><strong>Select LRs</strong><small>{chosen.length?`${chosen.length} selected`:'Use the register below'}</small></div></div><ChevronRight size={16}/><div><span>02</span><div><strong>Choose layout</strong><small>{printLayout==='classic'?'Classic':printLayout==='clean'?'Print Clean':visualTemplates.find(t=>`visual:${t.id}`===printLayout)?.name||'Custom'}</small></div></div><ChevronRight size={16}/><div><span>03</span><div><strong>{chosen.length>PDF_BATCH_SIZE?'Batch PDFs (ZIP)':'Export PDF'}</strong><small>{chosen.length?`${Math.ceil(chosen.length/2)} A4 ${Math.ceil(chosen.length/2)===1?'sheet':'sheets'}`:'Print at actual size'}</small></div></div></div>
 <div className="batch-layout-heading"><span>Print design</span><span>Two different LRs per A4 sheet · No duplicate copies</span></div>
 <div className="batch-layout-options" role="group" aria-label="Choose receipt print layout">
  {([['classic','Classic','Original LR format'],['clean','Print Clean','Refined type and spacing']] as const).map(([variant,label,description])=><button key={variant} type="button" className={`layout-choice ${printLayout===variant?'is-selected':''}`} aria-label={`Use ${label} receipt print design`} aria-pressed={printLayout===variant} onClick={()=>setPrintLayout(variant)} disabled={busy}>
   <LayoutMini variant={variant} includeQr={printQR}/>
   <span className="layout-choice-caption"><span className="layout-choice-title">{label}{printLayout===variant&&<CheckCircle2 size={15} aria-hidden="true"/>}</span><span className="layout-choice-desc">{description}</span></span>
  </button>)}
  {visualTemplates.map(t=><button type="button" key={t.id} className={`layout-choice ${printLayout===`visual:${t.id}`?'is-selected':''}`}
   aria-label={`Use custom print design ${t.name}`} aria-pressed={printLayout===`visual:${t.id}`} onClick={()=>setPrintLayout(`visual:${t.id}`)} disabled={busy}>
   <span className="flex h-24 w-[69px] shrink-0 flex-col items-center justify-center gap-2 rounded border border-slate-300 bg-white text-violet-700">
    <Layers3 size={24} aria-hidden="true"/><span className="text-[9px] font-bold">CUSTOM LR</span>
   </span>
   <span className="layout-choice-caption"><span className="layout-choice-title">{t.name}{printLayout===`visual:${t.id}`&&<CheckCircle2 size={15} aria-hidden="true"/>}</span><span className="layout-choice-desc">Published v{t.version} · editable vector</span></span>
  </button>)}
 </div>
 <label className="mt-4 flex cursor-pointer flex-wrap items-center gap-3 rounded-xl border border-violet-100 bg-violet-50/60 px-3 py-3 text-sm">
    <input type="checkbox" aria-label="Include QR code on each LR" className="h-4 w-4 accent-violet-600" checked={printQR} onChange={e=>setPrintQR(e.target.checked)} disabled={busy}/>
    <QrCode size={19} aria-hidden="true" className="text-violet-600"/>
    <span className="font-semibold text-slate-800">Include QR Code</span>
    <span className="text-xs text-slate-500">{printQR?'Enabled for both LR halves':'QR codes will be omitted from the PDF'}</span>
   </label>
  <div className="print-actions">
   <Button variant="outline" disabled={busy||exporting||!chosen.length}
    onClick={()=>setPreview({name:`${Math.min(chosen.length,PDF_BATCH_SIZE)} of ${chosen.length} selected LRs`,url:url('preview',{layout:printLayout,qr:printQR?'1':'0',keys:chosen.slice(0,PDF_BATCH_SIZE).join(',')})})}>
     <FileText size={16}/> {chosen.length>PDF_BATCH_SIZE?'Preview first 100':'Preview selected'}
   </Button>
   {chosen.length>PDF_BATCH_SIZE?
     <Button disabled={busy||exporting} onClick={()=>void downloadSelectedBatches()}>
      <ArrowDownToLine size={16}/> Download {Math.ceil(chosen.length/PDF_BATCH_SIZE)} PDFs (ZIP)
     </Button>
    :chosen.length&&!busy&&!exporting?
     <Button asChild><a href={url('pdf',{layout:printLayout,qr:printQR?'1':'0',keys:chosen.join(',')})}><ArrowDownToLine size={16}/> Download PDF</a></Button>
    :<Button disabled><ArrowDownToLine size={16}/> Download PDF</Button>}
   <span className="batch-layout-note">{chosen.length>PDF_BATCH_SIZE?`Up to 100 LRs per PDF · ${Math.ceil(chosen.length/PDF_BATCH_SIZE)} PDF files in one ZIP`:chosen.length%2===1?'Final sheet: bottom slot blank.':'Print at 100% / actual size.'}</span>
  </div>
  {exportProgress&&<div className="mt-3 flex flex-wrap items-center gap-3 rounded-xl border border-violet-200 bg-violet-50 p-3" role="status" aria-live="polite">
   <div className="flex-1 text-sm font-medium text-violet-950">{exportProgress.completed===exportProgress.total?'Packaging ZIP archive…':`Generating PDF ${exportProgress.completed+1} of ${exportProgress.total}…`}
    <p className="mt-1 text-xs font-normal text-violet-700">{exportProgress.receipts} of {chosen.length} LRs processed</p></div>
   <progress aria-label="Bulk PDF download progress" max={exportProgress.total} value={exportProgress.completed} className="h-2 w-28 accent-violet-600"/>
   <Button variant="outline" size="sm" onClick={()=>exportAbort.current?.abort()}>Cancel export</Button>
  </div>}
 </section></div></>}
  {isAdmin&&tab==='recycle'&&<section className="studio-panel space-y-5 p-5 sm:p-7">
    <div className="flex flex-wrap items-start justify-between gap-4"><div><h2 className="text-xl font-bold">Recycle Bin</h2><p className="mt-1 text-sm text-slate-600">Removed workspaces are hidden from the LR register, new PDF exports and online QR details until restored. They are permanently purged on the first authenticated visit after 30 days.</p></div>
      <Button disabled={busy||recycle.length===0} variant="outline" onClick={emptyRecycle} className="border-red-200 text-red-700 hover:bg-red-50"><Trash2 size={17}/> Empty Recycle Bin</Button></div>
    {recycle.length===0?<div className="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-5 py-10 text-center text-slate-500"><ArchiveRestore className="mx-auto mb-3 text-violet-400" size={32}/><p className="font-semibold">Recycle Bin is empty</p><p className="mt-1 text-sm">Removed workspaces will appear here for 30 days.</p></div>:
     <div className="grid gap-3">{recycle.map(ws=><div className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-slate-200 bg-white p-4" key={ws.id}>
       <div className="min-w-0"><h3 className="break-words font-semibold">{ws.name}</h3><p className="mt-1 text-xs text-slate-500">{ws.count} LRs {ws.sheet&&ws.sheet!=='CSV'?` · ${ws.sheet}`:''} {ws.legacy?' · Older import':''}</p><p className="mt-2 text-xs text-amber-700">Scheduled for deletion: {new Date(ws.expires_at).toLocaleDateString('en-IN',{day:'numeric',month:'short',year:'numeric'})}</p></div>
       <div className="flex flex-wrap gap-2"><Button disabled={busy} variant="outline" onClick={()=>restoreWorkspace(ws)}><RotateCcw size={16}/> Restore</Button><Button disabled={busy} variant="outline" className="border-red-200 text-red-700 hover:bg-red-50" onClick={()=>purgeWorkspace(ws)}><Trash2 size={16}/> Delete permanently</Button></div>
      </div>)}</div>}
    <p className="text-xs text-slate-500">Deletion runs on the first authenticated application request after the 30-day deadline, even without cron. Backups managed outside FreightSlip may have a separate retention policy.</p>
   </section>}
  {isAdmin&&tab==='settings'&&<section className="studio-panel max-w-3xl space-y-5 p-5 sm:p-7">
   <div><h2 className="text-lg font-bold">Printed office details</h2><p className="mt-1 text-sm text-slate-600">These values replace corresponding Excel columns when you validate a new workbook. Existing issued receipts and saved PDFs do not change. Revalidate any earlier draft after saving.</p></div>
   {officeSettings?<div className="grid gap-5">
    <label className="grid gap-2 text-sm font-semibold text-slate-800">Transport name<Input maxLength={300} value={officeSettings.carrier} onChange={e=>setOfficeSettings(old=>old?{...old,carrier:e.target.value}:old)} placeholder="Transport name"/></label>
    <label className="grid gap-2 text-sm font-semibold text-slate-800">Transport address<textarea maxLength={300} rows={2} value={officeSettings.address} onChange={e=>setOfficeSettings(old=>old?{...old,address:e.target.value}:old)} className="w-full resize-y rounded-xl border border-slate-300 bg-white p-3 font-normal outline-none focus:border-violet-500 focus:ring-2 focus:ring-violet-100"/></label>
    <label className="grid gap-2 text-sm font-semibold text-slate-800">Consignor address<textarea maxLength={300} rows={2} value={officeSettings.consignor_address} onChange={e=>setOfficeSettings(old=>old?{...old,consignor_address:e.target.value}:old)} className="w-full resize-y rounded-xl border border-slate-300 bg-white p-3 font-normal outline-none focus:border-violet-500 focus:ring-2 focus:ring-violet-100"/></label>
    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-5"><p className="text-xs text-slate-500">Saved privately in storage/settings.json · Revision {settingsVersion}</p><Button disabled={busy} onClick={saveOfficeSettings}><Save size={17}/> Save office details</Button></div>
   </div>:<p className="text-sm text-slate-500">Loading office settings…</p>}
  </section>}
 {isAdmin&&tab==='import'&&<><div className="my-5 grid grid-cols-3 gap-2 text-sm font-semibold sm:gap-4">{['Upload','Map fields','Review & issue'].map((s,i)=><div key={s} className={`rounded-xl border p-3 ${i===(review?2:upload?1:0)?'border-[#c7bcff] bg-[#f2eeff]':'border-[#e7e6f1] bg-white'}`}><span className="mr-2 text-xs text-[#5339d6]">0{i+1}</span>{s}</div>)}</div>
 <section className="rounded-2xl border border-[#e7e6f1] bg-white p-5 sm:p-6"><div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="text-xl font-bold">Source workbook</h2><p className="mt-1 text-sm text-slate-600">XLSX or UTF-8 CSV · Automatic column matching · Each issued import becomes a separate workspace · Up to 5 MB</p></div><Button asChild variant="outline"><a href={`${BASE}/example-dispatch.csv`} download><Download size={16}/> Example CSV</a></Button></div>
 <div className={`my-4 rounded-xl border-2 border-dashed p-4 text-center ${drag?'border-[#6b4df2] bg-[#f2eeff]':'border-slate-200 bg-slate-50'}`} onDragOver={e=>{e.preventDefault();setDrag(true);}} onDragLeave={()=>setDrag(false)} onDrop={e=>{e.preventDefault();setDrag(false);if(!busy&&e.dataTransfer.files[0])chooseWorkbook(e.dataTransfer.files[0]);}}>
 <CloudUpload className="mx-auto mb-2 text-[#5339d6]" size={30}/><p className="mb-2 break-all text-sm font-semibold">{file?.name||'Drag a spreadsheet here'}</p><input hidden type="file" accept=".xlsx,.csv" ref={picker} onChange={e=>{const source=e.target.files?.[0];if(source)chooseWorkbook(source);e.target.value='';}}/><Button variant="outline" disabled={busy} onClick={()=>picker.current?.click()}>Choose file</Button></div>
 <div className="flex flex-wrap items-end gap-3"><label className="grid gap-1 text-xs font-semibold text-slate-700">Header row (optional)<Input type="number" min={1} max={100} placeholder="Auto-detect" value={header} onChange={e=>setHeader(e.target.value)} className="w-32"/></label><Button disabled={!file||busy} onClick={()=>{if(file)void readWorkbook(file,header,upload?.sheet==='CSV'?'':upload?.sheet);}}>{busy?'Reading…':'Re-read workbook'} <ArrowRight size={16}/></Button>{upload&&upload.sheets.length>1&&<label className="grid gap-1 text-xs font-semibold">Worksheet<select value={upload.sheet} disabled={busy} onChange={e=>{if(file){setHeader('');void readWorkbook(file,'',e.target.value);}}}>{upload.sheets.map(name=><option key={name}>{name}</option>)}</select></label>}</div></section>
 {upload&&<section className="mt-5 rounded-2xl border border-[#e7e6f1] bg-white p-5 sm:p-6"><div className="flex flex-wrap justify-between gap-3"><div><h2 className="text-xl font-bold">Confirm mapping and rules</h2><p className="mt-1 break-all text-sm text-slate-600">{upload.file} · {upload.sheet} · Header row {upload.header} · {upload.rows} data rows</p></div>{upload.assistant&&<Button variant="outline" disabled={busy} onClick={()=>run(async()=>{const d=await api<{suggestions:Suggestion[]}>('suggest',{upload:upload.upload});setSuggestions(d.suggestions);if(!d.suggestions.length)setNote('No confident suggestions. You can map the columns manually.');})}><Sparkles size={16}/> Suggest mappings</Button>}</div>
 {upload.inferred.includes('address')&&<p className="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">The unnamed column matches the Shriram Transport address. Confirm it under Source columns.</p>}
 <details className="my-4 rounded-xl border border-slate-200 p-4" open={upload.inferred.length>0||upload.required.some(k=>map[k]===undefined)||suggestions.length>0||undefined}><summary className="text-sm font-semibold">Source columns · {Object.keys(map).length} mapped</summary><div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{Object.entries(upload.fields).map(([k,label])=><label key={k} className="grid min-w-0 gap-1 text-xs font-semibold">{label}{upload.required.includes(k)?' *':''}<select aria-label={label} value={map[k]??''} onChange={e=>changeField(k,e.target.value)}><option value="">Not mapped</option>{upload.headers.map((h,i)=><option key={i} value={i}>{i+1} · {h||'(untitled)'}</option>)}</select>{suggestions.filter(s=>s.field===k).map(s=><button type="button" key={s.column} className="text-left text-[#5339d6] underline" onClick={()=>{changeField(k,String(s.column));setSuggestions(old=>old.filter(x=>x.field!==k));}}>Use suggestion: {upload.headers[s.column]}</button>)}</label>)}</div></details>
 <div className="grid gap-4 sm:grid-cols-3"><label className="grid gap-1 text-sm font-semibold">LR issue date source<select value={date} onChange={e=>{setDate(e.target.value);invalidate();}}><option value="">Select date source</option><option value="lr_date" disabled={map.lr_date===undefined}>Use LR_DATE</option><option value="so_date" disabled={map.so_date===undefined}>Use SO_DATE (confirmed)</option><option value="override">Set one date for batch</option></select></label>{date==='override'&&<label className="grid gap-1 text-sm font-semibold">Batch issue date<Input type="date" value={override} onChange={e=>{setOverride(e.target.value);invalidate();}}/></label>}<label className="grid gap-1 text-sm font-semibold">Meaning of FRIGHT / freight<select value={freight} onChange={e=>{setFreight(e.target.value);invalidate();}}><option value="">Select freight rule</option><option value="base">Base freight before charges</option><option value="total">Final total including charges</option></select></label></div>
 <label className="my-4 flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" checked={allowZero} onChange={e=>{setAllowZero(e.target.checked);invalidate();}}/> I verified that zero freight is intentional for every zero-freight row in this batch.</label><Button disabled={busy||!date||!freight||(date==='override'&&!override)||(!upload.rows||upload.required.some(k=>map[k]===undefined))} onClick={()=>run(async()=>{const r=await api<Review>('review',{upload:upload.upload,map,rules:{date,override,freight,allow_zero:allowZero}});setReview(r);setSelection([]);})}>Validate receipts <ArrowRight size={16}/></Button></section>}</>}
 {isAdmin&&tab==='users'&&<AccessManager workspaces={workspaces}/>} 
  {isAdmin&&tab==='print_design'&&<PrintDesignEditor sampleKey={records[0]?.key} onSaved={d=>{setPrintLayout(d.default_layout);setPrintQR(d.default_qr);}}/>}
  {isAdmin&&tab==='visual_designer'&&<VisualPrintDesigner sampleKey={records[0]?.key} samples={records.slice(0,100).map(g=>({key:g.key,id:g.id}))} onPublished={setVisualTemplates}/>}

  {(tab==='register'||(isAdmin&&tab==='import'&&review))&&<section className="mt-5 rounded-2xl border border-[#e7e6f1] bg-white p-5 sm:p-6"><div className="mb-4 flex flex-wrap justify-between gap-3"><div><h2 className="text-xl font-bold">{tab==='register'?'LR register':'Batch review'}</h2><p className="mt-1 text-sm text-slate-600">{tab==='register'?`${matching.length} in this view · ${chosen.length} selected`:`${shown.filter(g=>g.status==='ready').length} ready · ${shown.filter(g=>g.status==='blocked').length} blocked · ${shown.filter(g=>g.status==='issued').length} previously issued · ${chosen.length} selected`}</p></div><div className="flex flex-wrap items-center gap-2"><span className="text-sm font-medium">Two different LRs per A4 sheet</span>{tab==='register'?<><Button variant="outline" disabled={busy||exporting||!matching.length} onClick={()=>allMatchingSelected?(setSelection([]),setNote('Selection cleared.')):selectAllMatching(matching)}>{allMatchingSelected?'Clear all':`Select all ${matching.length}`}</Button><div className="filter-pills" aria-label="Filter receipts"><button type="button" aria-pressed={filter==='all'} onClick={()=>setFilter('all')}>All <span>{matching.length}</span></button><button type="button" aria-pressed={filter==='selected'} onClick={()=>setFilter('selected')}>Selected <span>{chosen.length}</span></button></div><div className="relative"><Search className="absolute left-3 top-3 text-slate-500" size={16}/><Input aria-label="Search receipts" placeholder="Search LR, consignee, town" className="pl-9" value={query} onChange={e=>setQuery(e.target.value)}/></div></>:<><Button variant="outline" disabled={busy||!shown.some(g=>g.status==='ready')} onClick={()=>setSelection(shown.filter(g=>g.status==='ready').slice(0,100).map(g=>g.key))}>Select up to 100 ready</Button><Button disabled={busy||!chosen.length} onClick={()=>{if(!review||!window.confirm(`Issue ${chosen.length} receipt(s)? Issued records are saved and cannot be changed here.`))return;void run(async()=>{await api('issue',{review:review.review,keys:chosen});setReview(old=>old?{...old,groups:old.groups.map(g=>chosen.includes(g.key)?{...g,status:'issued',issues:['Issued. Reprint this receipt from the register.']}:g)}:null);setSelection([]);setNote(`Issued ${chosen.length} receipt(s). Open the register to download PDFs.`);});}}><FileCheck2 size={16}/> Issue selected</Button></>}</div></div>
 <div className="overflow-x-auto rounded-xl border border-slate-200"><table className="register-table w-full text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-600"><tr><th scope="col">{tab==='register'?<input type="checkbox" aria-label="Select all matching LRs" checked={allMatchingSelected} disabled={busy||exporting||!matching.length} onChange={()=>allMatchingSelected?(setSelection([]),setNote('Selection cleared.')):selectAllMatching(matching)}/>:<span className="sr-only">Select</span>}</th><th scope="col">LR number</th><th scope="col">Consignee / destination</th><th scope="col">Issue date</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>{shown.map(g=><tr className="border-t border-slate-200" key={g.key}><td>{(g.status==='ready'||tab==='register')&&<input type="checkbox" aria-label={`Select ${g.id}`} checked={selection.includes(g.key)} onChange={()=>toggle(g.key)} disabled={busy||exporting||(tab==='import'&&!selection.includes(g.key)&&selection.length>=100)}/>}</td><td className="font-bold whitespace-nowrap">{g.id||'Missing LR No.'}<div className="text-xs font-normal text-slate-500">{tab==='import'?`Rows ${g.rows.join(', ')}`:`${g.items.length} line item(s)`}{tab==='register'&&workspaceFilter==='all'&&g.source_file?<div className="mt-1 max-w-[180px] truncate" title={g.source_file}>{g.source_file}</div>:null}</div></td><td>{g.common.consignee}<div className="text-xs text-slate-500">{g.common.destination}</div></td><td className="whitespace-nowrap">{g.common.date||'—'}</td><td><span className={`whitespace-nowrap rounded-full px-2 py-1 text-xs font-semibold ${statusClasses[g.status]}`}>{labels[g.status]}</span>{!!g.issues.length&&<details className="mt-2 min-w-28 text-xs"><summary>{g.issues.length} note(s)</summary><ul className="mt-1 list-disc pl-3">{g.issues.map((s,i)=><li key={i}>{s}</li>)}</ul></details>}</td><td className="whitespace-nowrap">{(tab==='register'||g.status==='ready')&&<Button variant="ghost" onClick={()=>show(g)}><FileText size={15}/> Preview</Button>}{tab==='register'&&<Button asChild variant="outline"><a href={url('pdf',{layout:printLayout,qr:printQR?'1':'0',keys:g.key})}><Download size={15}/> PDF</a></Button>}{tab==='register'&&<a className="ml-2 text-xs underline" href={url('details',{key:g.key})} target="_blank" rel="noreferrer">Details</a>}</td></tr>)}{!shown.length&&<tr><td colSpan={6} className="text-center text-slate-600">{busy?'Loading receipts…':error&&tab==='register'?'Register unavailable. Select LR Register to retry.':!records.length&&tab==='register'?(isAdmin?'No issued receipts yet. Import a workbook to get started.':'No LRs in your assigned workspaces.'):'No matching receipts.'}</td></tr>}</tbody></table></div></section>}
 </main></div></div><dialog ref={dialog} onClose={()=>setPreview(null)} className="m-auto w-[min(95vw,1100px)] rounded-2xl bg-white p-0 shadow-xl backdrop:bg-black/50">{preview&&<><div className="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3"><h2 className="font-bold">LR preview · {preview.name}</h2><Button variant="ghost" aria-label="Close preview" onClick={()=>dialog.current?.close()}><X size={20}/></Button></div><iframe title={`LR preview ${preview.name}`} src={preview.url} className="h-[72vh] w-full" sandbox="allow-same-origin"/></>}</dialog></>;
}
