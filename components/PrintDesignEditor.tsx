'use client';

import {useEffect,useState} from 'react';
import {CheckCircle2,Eye,Printer,RotateCcw,Save,SlidersHorizontal} from 'lucide-react';
import {Button} from '@/components/ui/button';
import {Input} from '@/components/ui/input';
import {api,url} from '@/lib/api';

export type PrintStyle={accent:string;header_pt:number;font:'sans'|'serif';shade?:string;border?:'standard'|'strong'};
export type PrintDesign={default_layout:'classic'|'clean';default_qr:boolean;classic:PrintStyle;clean:PrintStyle};
type Response={version:number;design:PrintDesign};
const defaults:PrintDesign={
 default_layout:'classic',default_qr:true,
 classic:{accent:'#000000',header_pt:20,font:'sans'},
 clean:{accent:'#000000',header_pt:21,font:'sans',shade:'#f5f5f5',border:'standard'}
};
const accents=[['#000000','Black'],['#1e293b','Slate'],['#4338ca','Violet'],['#047857','Forest']];
const shades=[['#f5f5f5','Light gray'],['#ffffff','White'],['#f0f4ff','Pale blue'],['#ecfdf5','Pale green'],['#fff7ed','Pale peach']];
const copy=(d:PrintDesign):PrintDesign=>JSON.parse(JSON.stringify(d)) as PrintDesign;
export function PrintDesignEditor({sampleKey,onSaved}:{sampleKey?:string;onSaved?:(design:PrintDesign)=>void}){
 const [design,setDesign]=useState<PrintDesign>(copy(defaults));
 const [version,setVersion]=useState(0);
 const [busy,setBusy]=useState(false);
 const [loading,setLoading]=useState(true);
 const [error,setError]=useState('');
 const [notice,setNotice]=useState('');
 const [saved,setSaved]=useState<PrintDesign>(copy(defaults));
 const dirty=JSON.stringify(design)!==JSON.stringify(saved);
 useEffect(()=>{
  let live=true;
  api<Response>('print_design').then(r=>{if(!live)return;setDesign(copy(r.design));setSaved(copy(r.design));setVersion(r.version);})
   .catch(err=>{if(live)setError(err instanceof Error?err.message:'Could not load print designs.');})
   .finally(()=>{if(live)setLoading(false);});
  return()=>{live=false;};
 },[]);
 function update<K extends keyof PrintDesign>(key:K,value:PrintDesign[K]){
  setDesign(old=>({...old,[key]:value}));setNotice('');
 }
 function updateStyle(layout:'classic'|'clean',partial:Partial<PrintStyle>){
  setDesign(old=>({...old,[layout]:{...old[layout],...partial}}));setNotice('');
 }
 async function save(){
  setBusy(true);setError('');setNotice('');
  try{
   const r=await api<Response>('print_design',{version,design});
   setDesign(copy(r.design));setSaved(copy(r.design));setVersion(r.version);
   onSaved?.(r.design);
   setNotice('Print designs saved. Both receipt templates use these settings for future previews and downloads.');
  }catch(e){setError(e instanceof Error?e.message:'Could not save the print design.');}
  finally{setBusy(false);}
 }
 function preview(layout:'classic'|'clean'){
  if(!sampleKey)return;
  window.open(url('preview',{keys:sampleKey,layout,qr:design.default_qr?'1':'0'}),'_blank','noopener,noreferrer');
  if(dirty)setNotice('Preview displays the last saved design. Save changes to see your current edits in an actual LR.');
 }
 return <section className="space-y-5" aria-label="Administrator print design editor">
  <div className="flex flex-wrap items-start justify-between gap-2">
   <div><h2 className="flex items-center gap-2 text-xl font-bold text-slate-900"><SlidersHorizontal className="text-violet-600" size={22}/> Print Design Editor</h2>
    <p className="mt-1 text-sm text-slate-600">Change the print styles without altering LR numbers, freight fields, page geometry, or the two-LRs-per-A4 rule.</p></div>
   <span className="rounded-full bg-violet-50 px-3 py-1 text-xs font-bold text-violet-700">ADMIN ONLY</span>
  </div>
  {error&&<p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{error}</p>}
  {notice&&<p role="status" className="flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800"><CheckCircle2 size={17}/>{notice}</p>}
  <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
   <h3 className="text-base font-bold">Default print selection</h3>
   <div className="mt-4 grid gap-4 sm:grid-cols-2">
    <label className="grid gap-2 text-sm font-semibold">Default layout
     <select className="rounded-xl border border-slate-200 bg-white px-3 py-3" value={design.default_layout} disabled={loading||busy}
      onChange={e=>update('default_layout',e.target.value==='clean'?'clean':'classic')}>
      <option value="classic">Classic</option><option value="clean">Print Clean</option>
     </select>
    </label>
    <label className="flex items-center gap-3 self-end rounded-xl border border-slate-200 p-3 text-sm font-semibold">
     <input type="checkbox" className="h-4 w-4 accent-violet-600" checked={design.default_qr} disabled={loading||busy}
      onChange={e=>update('default_qr',e.target.checked)}/>
     Include QR codes by default
    </label>
   </div>
  </div>
  <div className="grid gap-4 xl:grid-cols-2">
   {(['classic','clean'] as const).map(layout=>{
    const style=design[layout];const title=layout==='classic'?'Classic':'Print Clean';
    return <div key={layout} className="min-w-0 space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
     <div className="flex items-center justify-between gap-2"><h3 className="text-base font-bold">{title}</h3><span className="text-xs text-slate-500">A4 portrait</span></div>
     <div className="rounded-xl border border-slate-200 p-3" style={{background:layout==='clean'?style.shade:'white'}}>
      <div className="mx-auto max-w-sm space-y-2 bg-white p-4 text-center shadow-sm">
       <p className="leading-tight font-bold" style={{color:style.accent,fontSize:Math.min(25,style.header_pt+1),fontFamily:style.font==='serif'?'Georgia,serif':'Arial,sans-serif'}}>SHRIRAM TRANSPORT</p>
       <p className="text-[10px] text-slate-500">MILKAT 2345 · Pune Saswad Road · Maharashtra</p>
       <div className="border-t border-slate-300 pt-2 text-left text-[11px]">LR Number &nbsp; • &nbsp; Consignor &nbsp; • &nbsp; Consignee</div>
       <div className="grid h-10 grid-cols-[1fr_2fr_1fr] border text-[10px]" style={{borderColor:style.border==='strong'?'#222':'#aaa'}}>
        <span className="border-r p-1" style={{borderColor:style.border==='strong'?'#222':'#aaa'}}>Qty</span><span className="border-r p-1" style={{borderColor:style.border==='strong'?'#222':'#aaa'}}>Particulars</span><span className="p-1">Freight</span>
       </div>
      </div>
     </div>
     <label className="grid gap-2 text-sm font-semibold">Header size · {style.header_pt} pt
      <Input type="range" min={17} max={23} step={1} disabled={loading||busy} value={style.header_pt}
       onChange={e=>updateStyle(layout,{header_pt:Number(e.target.value)})}/>
     </label>
     <label className="grid gap-2 text-sm font-semibold">Heading font
      <select disabled={loading||busy} value={style.font} className="rounded-xl border border-slate-200 bg-white px-3 py-2.5"
       onChange={e=>updateStyle(layout,{font:e.target.value==='serif'?'serif':'sans'})}>
       <option value="sans">Sans-serif</option><option value="serif">Serif</option>
      </select>
     </label>
     <fieldset className="space-y-2"><legend className="text-sm font-semibold">Heading color</legend>
      <div className="flex flex-wrap gap-2">{accents.map(([hex,name])=><button key={hex} type="button" disabled={loading||busy}
       aria-label={name} aria-pressed={style.accent===hex} title={name}
       className={`h-10 w-10 rounded-full border-4 shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-violet-600 ${style.accent===hex?'ring-2 ring-violet-600 ring-offset-2':'border-white'}`}
       style={{backgroundColor:hex}} onClick={()=>updateStyle(layout,{accent:hex})}/>)}</div>
     </fieldset>
     {layout==='clean'&&<div className="grid gap-3 sm:grid-cols-2">
      <label className="grid gap-2 text-sm font-semibold">Section shading
       <select disabled={loading||busy} value={style.shade} className="min-w-0 rounded-xl border border-slate-200 bg-white px-2 py-2.5"
        onChange={e=>updateStyle(layout,{shade:e.target.value})}>
        {shades.map(([hex,name])=><option key={hex} value={hex}>{name}</option>)}
       </select>
      </label>
      <label className="grid gap-2 text-sm font-semibold">Grid strokes
       <select disabled={loading||busy} value={style.border} className="min-w-0 rounded-xl border border-slate-200 bg-white px-2 py-2.5"
        onChange={e=>updateStyle(layout,{border:e.target.value==='strong'?'strong':'standard'})}>
        <option value="standard">Standard</option><option value="strong">Bold</option>
       </select>
      </label>
     </div>}
     <Button type="button" variant="outline" disabled={loading||busy||!sampleKey} onClick={()=>preview(layout)}>
      <Eye size={16}/> Preview saved {title} LR
     </Button>
    </div>;
   })}
  </div>
  <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4">
   <div><p className="text-sm font-semibold">{dirty?'Unsaved design changes':'All design changes saved'}</p>
    <p className="mt-1 text-xs text-slate-500">Revision {version} · Settings stored privately in print-design.json</p></div>
   <div className="flex flex-wrap gap-2">
    <Button variant="outline" disabled={loading||busy} onClick={()=>{setDesign(copy(defaults));setNotice('Factory settings are ready. Click Save to apply them.');}}><RotateCcw size={16}/> Restore defaults</Button>
    <Button disabled={loading||busy||!dirty} onClick={()=>void save()}><Save size={16}/> Save Print Designs</Button>
   </div>
  </div>
  <p className="flex items-center gap-2 text-xs text-slate-500"><Printer size={15}/> An existing issued LR is needed for a full PDF preview. The miniature updates immediately, but the server preview uses the last saved style.</p>
 </section>;
}
