'use client';

import {useEffect,useMemo,useRef,useState} from 'react';
import type {PointerEvent,KeyboardEvent,ChangeEvent} from 'react';
import {ArrowDownToLine,ArrowUp,ArrowDown,Bold,Check,Copy,Eye,FilePlus,Grip,Italic,Layers,Minus,Plus,Redo2,RotateCcw,Save,ScanLine,Trash2,Type,Underline,Undo2,Upload,ZoomIn,ZoomOut} from 'lucide-react';
import {Button} from '@/components/ui/button';
import {api,url} from '@/lib/api';

type Kind='field'|'text'|'rect'|'line'|'qr'|'image';
type Font='sans'|'serif'|'mono';
type Align='left'|'center'|'right';
export type VisualLayer={id:string;kind:Kind;x:number;y:number;w:number;h:number;size:number;
 font:Font;bold:boolean;italic:boolean;underline:boolean;align:Align;
 color:string;fill:string;stroke:string;strokeWidth:number;text:string;binding:string;data:string};
type VisualTemplate={id:string;name:string;base:'classic'|'clean';draft:VisualLayer[];
 published:null|{version:number;name:string;elements:VisualLayer[];published_at:string};
 history:{version:number;name:string;elements:VisualLayer[];published_at:string}[]};
type Library={revision:number;templates:VisualTemplate[]};
type Validation={errors:string[];warnings:string[]};
const W=595.27,H=410;
const bindings:[string,string][]=[
 ['carrier','Transport name'],['transport_address','Transport address'],['lr_no','LR number'],
 ['date','Receipt date'],['consignor','Consignor'],['consignor_address','Consignor address'],
 ['consignee','Consignee'],['destination','Destination'],['product','Products / rows'],
 ['qty','Quantity / rows'],['ref_no','Reference numbers'],['weight','Weight'],
 ['declared_value','Declared value'],['base_freight','Base freight'],['total_freight','Freight total'],
 ['charge_bc','Booking charges'],['charge_hamali','Hamali'],['charge_other','Other charges'],
 ['charge_delivery','Delivery'],['charge_risk','Risk'],['continuation','Continuation'],
 ['signature_name','Signatory'],['draft_stamp','Draft mark']
];
const sample:Record<string,string>={carrier:'SHRIRAM TRANSPORT',transport_address:'MILKAT 2345, Pune Saswad Road, Uruli Devachi',
 lr_no:'LR-2026-00125',date:'21-11-2026',consignor:'SHRIRAM ENTERPRISES',consignor_address:'C/O Shriram Enterprises\nUrulidevachi, Pune 412308',
 consignee:'Example Customer Pvt Ltd',destination:'Pune, Maharashtra',product:'Packaging Material\nMachine Spare Parts',qty:'12\n4',ref_no:'REF-108\nREF-109',weight:'340',
 declared_value:'15,000.00',base_freight:'2,000.00',total_freight:'2,150.00',charge_bc:'100.00',charge_hamali:'50.00',
 charge_other:'',charge_delivery:'',charge_risk:'',continuation:'',signature_name:'SHRIRAM TRANSPORT',draft_stamp:'DRAFT'};
const copy=<T,>(x:T):T=>JSON.parse(JSON.stringify(x)) as T;
const round=(v:number)=>Math.round(v*2)/2;
const clamp=(v:number,min:number,max:number)=>Math.max(min,Math.min(max,v));
const uniqueId=()=>`layer-${Math.random().toString(36).slice(2,10)}`;
const blank=(kind:Kind,extra:Partial<VisualLayer>={}):VisualLayer=>({id:uniqueId(),kind,x:kind==='qr'?265:90,y:kind==='qr'?285:210,w:kind==='qr'?84:kind==='line'||kind==='rect'?180:150,h:kind==='qr'?84:kind==='rect'?55:kind==='line'?1:23,size:10,font:'sans',bold:false,italic:false,
 underline:false,align:'left',color:'#111827',fill:'#f1f5f9',stroke:'#64748b',strokeWidth:.6,text:'New label',binding:'consignee',data:'',
 ...extra});
function layerName(layer:VisualLayer):string{return layer.kind==='field'?(bindings.find(([id])=>id===layer.binding)?.[1]??layer.binding):
 layer.kind==='text'?layer.text.slice(0,32)||'Text':layer.kind==='rect'?'Rectangle':layer.kind==='line'?'Line':layer.kind==='qr'?'QR code':'Image / Logo';}
function layerErrors(layers:VisualLayer[]):Validation{
 const errors:string[]=[],warnings:string[]=[];
 const bound=new Set(layers.filter(l=>l.kind==='field').map(l=>l.binding));
 for(const b of ['carrier','lr_no','date','consignor','consignee','destination','product','qty','total_freight'])if(!bound.has(b))errors.push(`Required field is missing: ${b}`);
 for(const l of layers){
  if(l.x<0||l.y<0||l.w<.5||l.h<.5||l.x+l.w>W+.01||l.y+l.h>H+.01)errors.push(`${layerName(l)} extends outside its LR half.`);
  if(l.kind==='qr'&&(l.w<62||l.h<62))errors.push('QR must be at least 62 × 62 pt.');
  if(l.size<5||l.size>32)errors.push(`${layerName(l)} must use a 5–32 pt font.`);
  if(['field','text'].includes(l.kind)&&l.h<l.size*.7)warnings.push(`${layerName(l)} has a short text box.`);
 }
 return {errors,warnings};
}
function Tool({icon:Icon,label,onClick,disabled=false,active=false}:{icon:typeof Plus;label:string;onClick:()=>void;disabled?:boolean;active?:boolean}){
 return <button type="button" title={label} aria-label={label} disabled={disabled} onClick={onClick}
 className={`inline-flex h-9 min-w-9 items-center justify-center rounded-lg border px-2 text-xs ${active?'border-violet-500 bg-violet-100 text-violet-800':'border-slate-200 bg-white text-slate-700 hover:bg-violet-50'} disabled:cursor-not-allowed disabled:opacity-40`}>
  <Icon size={16} aria-hidden="true"/>
 </button>;
}
export function VisualPrintDesigner({sampleKey,onPublished}:{sampleKey?:string;onPublished?:(list:{id:string;name:string;version:number}[])=>void}){
 const [library,setLibrary]=useState<Library>({revision:0,templates:[]});
 const [activeId,setActiveId]=useState('');
 const [layers,setLayers]=useState<VisualLayer[]>([]);
 const [name,setName]=useState('');
 const [selected,setSelected]=useState('');
 const [busy,setBusy]=useState(false),[loading,setLoading]=useState(true);
 const [error,setError]=useState(''),[note,setNote]=useState('');
 const [zoom,setZoom]=useState(100),[grid,setGrid]=useState(true);
 const [base,setBase]=useState<'classic'|'clean'>('clean'),[newName,setNewName]=useState('My LR Design');
 const [undo,setUndo]=useState<VisualLayer[][]>([]),[redo,setRedo]=useState<VisualLayer[][]>([]);
 const [dirty,setDirty]=useState(false);
 const canvas=useRef<SVGSVGElement|null>(null);
 const drag=useRef<{id:string;startX:number;startY:number;initial:VisualLayer;before:VisualLayer[];mode:'move'|'resize'}|null>(null);
 const current=library.templates.find(t=>t.id===activeId);
 const selection=layers.find(l=>l.id===selected);
 const check=useMemo(()=>layerErrors(layers),[layers]);
 const maxZoom=clamp(zoom,50,150);
 const load=(l:Library,id?:string)=>{
  setLibrary(l);const next=l.templates.find(t=>t.id===(id??activeId))??l.templates[0];
  setActiveId(next?.id??'');setName(next?.name??'');setLayers(copy(next?.draft??[]));setSelected('');
  setUndo([]);setRedo([]);setDirty(false);
 };
 useEffect(()=>{let live=true;api<Library>('visual_templates').then(r=>{if(live)load(r);})
  .catch(e=>{if(live)setError(e instanceof Error?e.message:'Unable to load templates.');})
  .finally(()=>{if(live)setLoading(false);});return()=>{live=false;};},[]); // eslint-disable-line react-hooks/exhaustive-deps
 const commit=(next:VisualLayer[])=>{setUndo(old=>[...old.slice(-39),copy(layers)]);setRedo([]);setLayers(next);setDirty(true);};
 const change=(layer:VisualLayer,updates:Partial<VisualLayer>)=>commit(layers.map(l=>l.id===layer.id?{...l,...updates}:l));
 const undoOnce=()=>{if(!undo.length)return;const last=undo[undo.length-1];setUndo(undo.slice(0,-1));setRedo(r=>[...r,copy(layers)]);setLayers(last);setDirty(true);};
 const redoOnce=()=>{if(!redo.length)return;const last=redo[redo.length-1];setRedo(redo.slice(0,-1));setUndo(r=>[...r,copy(layers)]);setLayers(last);setDirty(true);};
 const selectTemplate=(id:string)=>{if(dirty&&!window.confirm('Discard unsaved edits and open another design?'))return;
  const t=library.templates.find(t=>t.id===id);if(!t)return;setActiveId(id);setName(t.name);setLayers(copy(t.draft));setSelected('');setUndo([]);setRedo([]);setDirty(false);setNote('');setError('');};
 async function mutate(action:string,payload:Record<string,unknown>,focus?:string){
  setBusy(true);setError('');setNote('');
  try{const saved=await api<Library>(action,{revision:library.revision,...payload});load(saved,focus);return saved;}
  catch(e){setError(e instanceof Error?e.message:'Could not save the print template.');return null;}
  finally{setBusy(false);}
 }
 const create=async()=>{const before=library.templates.map(t=>t.id);
  const created=await mutate('visual_create',{base,name:newName.trim()});
  if(created){const t=created.templates.find(t=>!before.includes(t.id));if(t)load(created,t.id);setNote('New draft created. Drag its layers and save before publishing.');}
 };
 const save=async()=>{if(!current)return null;const saved=await mutate('visual_save',{id:activeId,name:name.trim(),elements:layers},activeId);
  if(saved)setNote('Draft saved. Preview it using an issued LR before publishing.');return saved;};
 const publish=async()=>{if(!current)return;if(dirty){setError('Save Draft first so the published version exactly matches the PDF preview.');return;}
  if(check.errors.length){setError(check.errors.join(' '));return;}
  const confirmWarnings=check.warnings.length>0;
  if(confirmWarnings&&!window.confirm('Some text boxes may overlap or be too short. Have you verified the saved PDF proof? Publish anyway?'))return;
  const saved=await mutate('visual_publish',{id:activeId,confirm_warnings:confirmWarnings},activeId);
  if(!saved)return;
  onPublished?.(saved.templates.filter(t=>t.published).map(t=>({id:t.id,name:t.name,version:t.published!.version})));
  setNote('Published. Download-only users can select this design for assigned LRs.');
 };
 const revert=async(version:number)=>{if(!current||!window.confirm(`Load published version ${version} as a draft? The current draft will be replaced.`))return;
  const saved=await mutate('visual_revert',{id:activeId,version},activeId);
  if(saved)setNote(`Version ${version} restored as a draft. Review and publish it to make it active.`);
 };
 const deleteDraft=async()=>{if(!current||current.published||!window.confirm('Permanently delete this unpublished draft?'))return;
  const saved=await mutate('visual_delete',{id:activeId});if(saved)setNote('Draft deleted.');
 };
 const preview=(pdf=false)=>{
  if(!current||!sampleKey){setError('Issue an LR first so the renderer has real data for the proof.');return;}
  if(dirty&&!published){setError('Save your draft before opening a full-size proof.');return;}
  const params={layout:`visual:${activeId}`,keys:sampleKey,qr:'1',template_draft:'1'};
  window.open(url(pdf?'pdf':'preview',params),'_blank','noopener,noreferrer');
 };
 const selectedCoord=(clientX:number,clientY:number)=>{const rect=canvas.current?.getBoundingClientRect();
  if(!rect)return{x:0,y:0};return{x:(clientX-rect.left)*W/rect.width,y:(clientY-rect.top)*H/rect.height};};
 function begin(e:PointerEvent<SVGGElement|SVGRectElement>,layer:VisualLayer,mode:'move'|'resize'){
  e.stopPropagation();if(busy)return;
  setSelected(layer.id);const pos=selectedCoord(e.clientX,e.clientY);
  drag.current={id:layer.id,startX:pos.x,startY:pos.y,initial:copy(layer),before:copy(layers),mode};
  e.currentTarget.setPointerCapture(e.pointerId);
 }
 function move(e:PointerEvent<SVGSVGElement>){const d=drag.current;if(!d)return;
  const pos=selectedCoord(e.clientX,e.clientY),deltaX=pos.x-d.startX,deltaY=pos.y-d.startY;
  const snap=(v:number)=>grid?Math.round(v/2)*2:round(v);
  setLayers(old=>old.map(l=>{
   if(l.id!==d.id)return l;
   if(d.mode==='move')return{...l,x:clamp(snap(d.initial.x+deltaX),0,W-l.w),y:clamp(snap(d.initial.y+deltaY),0,H-l.h)};
   const w=clamp(snap(d.initial.w+deltaX),l.kind==='qr'?62:5,W-l.x);
   const h=clamp(snap(d.initial.h+deltaY),l.kind==='qr'?62:4,H-l.y);
   return{...l,w,h};
  }));
 }
 function end(){const d=drag.current;if(!d)return;drag.current=null;
  setUndo(old=>[...old.slice(-39),d.before]);setRedo([]);setDirty(true);
 }
 function keyboard(e:KeyboardEvent<HTMLDivElement>){
  if(e.target instanceof HTMLInputElement||e.target instanceof HTMLTextAreaElement||e.target instanceof HTMLSelectElement)return;
  if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='z'){e.preventDefault();e.shiftKey?redoOnce():undoOnce();return;}
  if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='y'){e.preventDefault();redoOnce();return;}
  if(!selection)return;
  const dx=e.key==='ArrowLeft'?-1:e.key==='ArrowRight'?1:0,dy=e.key==='ArrowUp'?-1:e.key==='ArrowDown'?1:0;
  if(dx||dy){e.preventDefault();const step=e.shiftKey?10:1;change(selection,{x:clamp(selection.x+dx*step,0,W-selection.w),y:clamp(selection.y+dy*step,0,H-selection.h)});}
  if(e.key==='Delete'||e.key==='Backspace'){e.preventDefault();commit(layers.filter(l=>l.id!==selection.id));setSelected('');}
 }
 const add=(kind:Kind,binding?:string)=>{const elem=blank(kind,binding?{binding}:{});commit([...layers,elem]);setSelected(elem.id);};
 const duplicate=()=>{if(!selection)return;const l={...selection,id:uniqueId(),x:clamp(selection.x+8,0,W-selection.w),y:clamp(selection.y+8,0,H-selection.h)};commit([...layers,l]);setSelected(l.id);};
 const reorder=(delta:number)=>{if(!selection)return;const i=layers.findIndex(l=>l.id===selection.id),j=clamp(i+delta,0,layers.length-1);if(i===j)return;const next=[...layers];next.splice(i,1);next.splice(j,0,selection);commit(next);};
 async function uploadImage(e:ChangeEvent<HTMLInputElement>){const f=e.target.files?.[0];e.target.value='';if(!f)return;
  if(!['image/png','image/jpeg'].includes(f.type)||f.size>90000){setError('Use a PNG/JPEG image smaller than 90 KB.');return;}
  const reader=new FileReader();reader.onload=()=>{const data=reader.result;if(typeof data==='string')addImage(data);};reader.readAsDataURL(f);
 }
 function addImage(data:string){const elem=blank('image',{data,w:100,h:50});commit([...layers,elem]);setSelected(elem.id);}
 function displayedText(l:VisualLayer){return l.kind==='field'?sample[l.binding]||l.binding:l.text;}
 return <section className="space-y-4" aria-label="Full LR Visual Print Designer" tabIndex={0} onKeyDown={keyboard}>
  <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
   <div className="flex flex-wrap items-center justify-between gap-3">
    <div><h2 className="flex items-center gap-2 text-lg font-bold text-slate-900"><Layers size={20} className="text-violet-600"/> Visual Print Designer</h2>
     <p className="mt-1 text-xs text-slate-500">Design one LR half. The renderer repeats it for two different LRs on each A4 sheet.</p></div>
    <span className="rounded-lg bg-violet-50 px-3 py-1 text-xs font-bold text-violet-800">ADMIN ONLY · A4 LOCKED</span>
   </div>
   {error&&<p role="alert" className="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-800">{error}</p>}
   {note&&<p role="status" className="mt-3 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{note}</p>}
   <div className="mt-4 flex flex-wrap items-end gap-3">
    <label className="grid min-w-[190px] flex-1 gap-1 text-xs font-semibold text-slate-700">Saved templates
     <select value={activeId} disabled={busy||loading} onChange={e=>selectTemplate(e.target.value)} className="rounded-lg border border-slate-300 bg-white p-2.5 text-sm">
      {library.templates.length===0?<option value="">No designs yet — create one</option>:null}
      {library.templates.map(t=><option key={t.id} value={t.id}>{t.name} {t.published?`· v${t.published.version}`:'· draft'}</option>)}
     </select></label>
    <label className="grid min-w-[140px] gap-1 text-xs font-semibold text-slate-700">New design from
     <select value={base} onChange={e=>setBase(e.target.value==='classic'?'classic':'clean')} disabled={busy} className="rounded-lg border border-slate-300 bg-white p-2.5 text-sm">
      <option value="clean">Print Clean (vector)</option><option value="classic">Classic (vector)</option>
     </select></label>
    <label className="grid min-w-[155px] flex-1 gap-1 text-xs font-semibold text-slate-700">New design name
     <input value={newName} maxLength={70} onChange={e=>setNewName(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2.5 text-sm"/>
    </label>
    <Button variant="outline" disabled={busy||loading||library.templates.length>=20} onClick={()=>void create()}><FilePlus size={16}/> Create</Button>
   </div>
  </div>
  {current&&<>
   <div className="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white p-3">
    <Tool icon={Undo2} label="Undo (Ctrl+Z)" disabled={!undo.length} onClick={undoOnce}/>
    <Tool icon={Redo2} label="Redo (Ctrl+Shift+Z)" disabled={!redo.length} onClick={redoOnce}/>
    <Tool icon={ZoomOut} label="Zoom out" disabled={zoom<=50} onClick={()=>setZoom(z=>clamp(z-25,50,150))}/>
    <span className="rounded-lg bg-slate-50 px-2 py-2 text-xs font-semibold text-slate-600">{maxZoom}%</span>
    <Tool icon={ZoomIn} label="Zoom in" disabled={zoom>=150} onClick={()=>setZoom(z=>clamp(z+25,50,150))}/>
    <Tool icon={ScanLine} label="Snap to 2 pt grid" active={grid} onClick={()=>setGrid(x=>!x)}/>
    <span className="ml-auto text-xs text-slate-500">{layers.length} layers · W {W} × H {H} pt</span>
   </div>
   <div className="grid items-start gap-4 xl:grid-cols-[190px_minmax(0,1fr)_250px]">
    <aside className="min-w-0 space-y-3 rounded-2xl border border-slate-200 bg-white p-3" aria-label="Document layers">
     <h3 className="text-sm font-bold">Layers</h3>
     <div className="flex flex-wrap gap-1"><Tool icon={Type} label="Add text" onClick={()=>add('text')}/><Tool icon={Plus} label="Add box" onClick={()=>add('rect')}/><Tool icon={Minus} label="Add line" onClick={()=>add('line')}/><Tool icon={ScanLine} label="Add QR" onClick={()=>add('qr')}/></div>
     <label className="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 p-2 text-xs hover:bg-violet-50"><Upload size={14}/> Add PNG/JPEG logo <input type="file" accept="image/png,image/jpeg" onChange={e=>void uploadImage(e)} className="sr-only"/></label>
     <label className="grid gap-1 text-xs font-semibold text-slate-700">Add LR field
      <select value="" className="min-w-0 rounded-lg border border-slate-300 p-2 text-xs" onChange={e=>{if(e.target.value)add('field',e.target.value);}}>
       <option value="">Choose field…</option>{bindings.map(([id,label])=><option key={id} value={id}>{label}</option>)}
      </select></label>
     <div className="max-h-[520px] space-y-1 overflow-y-auto">{[...layers].reverse().map(layer=><button key={layer.id} type="button" title={layerName(layer)}
      className={`flex w-full items-center gap-2 overflow-hidden rounded-lg border px-2 py-2 text-left text-xs ${selected===layer.id?'border-violet-400 bg-violet-50 text-violet-900':'border-transparent text-slate-700 hover:bg-slate-50'}`}
      onClick={()=>setSelected(layer.id)}><Grip size={13} className="shrink-0 opacity-40"/><span className="truncate">{layerName(layer)}</span></button>)}</div>
    </aside>
    <div className="min-w-0 rounded-2xl border border-slate-200 bg-slate-100 p-3 sm:p-5">
     <p className="mb-3 text-center text-xs text-slate-500">Editable LR half · drag fields or resize the selected outline. Use Shift + arrows for 10 pt movement.</p>
     <div className="mx-auto overflow-auto rounded-lg border border-slate-200 bg-slate-50 p-2" style={{maxWidth:'100%'}}>
      <svg ref={canvas} viewBox={`0 0 ${W} ${H}`} role="img" aria-label="Visual layout of the upper LR on an A4 sheet" xmlns="http://www.w3.org/2000/svg"
       style={{display:'block',width:`${maxZoom}%`,minWidth:maxZoom>100?595:undefined,margin:'auto',background:'white',touchAction:'none'}}
       onPointerMove={move} onPointerUp={end} onPointerCancel={end} onPointerDown={()=>setSelected('')}>
       <defs><pattern id="lr-designer-grid" width="10" height="10" patternUnits="userSpaceOnUse"><path d="M 10 0 L 0 0 0 10" fill="none" stroke="#e9e5f6" strokeWidth=".45"/></pattern></defs>
       {grid&&<rect width={W} height={H} fill="url(#lr-designer-grid)" pointerEvents="none"/>}
       <rect x=".5" y=".5" width={W-1} height={H-1} stroke="#aab2c0" fill="none" pointerEvents="none"/>
       {layers.map(layer=>{
        const sel=selected===layer.id;
        const style={cursor:'move'};
        return <g key={layer.id} onPointerDown={e=>begin(e,layer,'move')} style={style}>
         {layer.kind==='rect'?<rect x={layer.x} y={layer.y} width={layer.w} height={layer.h} fill={layer.fill} stroke={layer.stroke} strokeWidth={layer.strokeWidth}/>:
          layer.kind==='line'?<line x1={layer.x} y1={layer.y} x2={layer.x+layer.w} y2={layer.y+layer.h} stroke={layer.stroke} strokeWidth={layer.strokeWidth}/>:
          layer.kind==='image'?<image href={layer.data} x={layer.x} y={layer.y} width={layer.w} height={layer.h} preserveAspectRatio="xMidYMid meet"/>:
          layer.kind==='qr'?<g><rect x={layer.x} y={layer.y} width={layer.w} height={layer.h} fill="white" stroke="#444" strokeWidth="1"/>
           {[0,1,2].map(n=><rect key={n} x={layer.x+5+(n===1?layer.w-30:0)} y={layer.y+5+(n===2?layer.h-30:0)} width="20" height="20" fill="white" stroke="#111" strokeWidth="3"/>)}
           <text x={layer.x+layer.w/2} y={layer.y+layer.h-6} textAnchor="middle" fontSize="6">QR PLACEHOLDER</text></g>:
          <text x={layer.x+(layer.align==='center'?layer.w/2:layer.align==='right'?layer.w:0)} y={layer.y+layer.size}
           textAnchor={layer.align==='center'?'middle':layer.align==='right'?'end':'start'}
           fontFamily={layer.font==='serif'?'Georgia,serif':layer.font==='mono'?'monospace':'Arial,sans-serif'} fontSize={layer.size}
           fontWeight={layer.bold?'bold':'normal'} fontStyle={layer.italic?'italic':'normal'} textDecoration={layer.underline?'underline':'none'} fill={layer.color}>
           {(displayedText(layer)||' ').split('\n').slice(0,5).map((line,i)=><tspan key={i} x={layer.x+(layer.align==='center'?layer.w/2:layer.align==='right'?layer.w:0)} dy={i===0?0:layer.size*1.2}>{line.length>38?line.slice(0,38)+'…':line}</tspan>)}
          </text>}
         <rect x={layer.x} y={layer.y} width={Math.max(3,layer.w)} height={Math.max(3,layer.h)} fill="transparent" stroke={sel?'#6943ee':'transparent'} strokeWidth={sel?1.4:0} strokeDasharray="3 2"/>
         {sel&&<rect x={layer.x+layer.w-5} y={layer.y+layer.h-5} width="10" height="10" fill="#fff" stroke="#6943ee" strokeWidth="2" cursor="nwse-resize" onPointerDown={e=>begin(e,layer,'resize')}/>}
        </g>;
       })}
      </svg>
     </div>
     <div className="mt-3 flex flex-wrap justify-center gap-3 text-xs text-slate-500"><span>Top half is editable</span><span>·</span><span>Lower half mirrors this layout</span><span>·</span><span>Actual PDF is authoritative</span></div>
    </div>
    <aside className="min-w-0 rounded-2xl border border-slate-200 bg-white p-3" aria-label="Selected layer properties">
     <h3 className="text-sm font-bold">Properties</h3>
     {!selection?<p className="mt-3 text-xs text-slate-500">Select a layer on the canvas or in the layer list. Every text field is independently movable and formattable.</p>:<div className="mt-3 space-y-3">
      <div className="flex items-center justify-between gap-2"><p className="min-w-0 truncate text-xs font-bold">{layerName(selection)}</p><div className="flex gap-1"><Tool icon={ArrowUp} label="Bring forward" onClick={()=>reorder(1)}/><Tool icon={ArrowDown} label="Send backward" onClick={()=>reorder(-1)}/></div></div>
      <div className="grid grid-cols-2 gap-2">{(['x','y','w','h'] as const).map(prop=><label key={prop} className="grid gap-1 text-xs font-semibold uppercase text-slate-600">{prop} (pt)
       <input type="number" min={(prop==='w'||prop==='h')?0.5:0} max={prop==='x'||prop==='w'?W:H} step="0.5" value={selection[prop]}
        onChange={e=>change(selection,{[prop]:Number(e.target.value)} as Partial<VisualLayer>)} className="w-full min-w-0 rounded-lg border border-slate-300 p-2 text-sm"/></label>)}</div>
      {selection.kind==='field'&&<label className="grid gap-1 text-xs font-semibold">LR data binding
       <select value={selection.binding} onChange={e=>change(selection,{binding:e.target.value})} className="rounded-lg border border-slate-300 p-2 text-xs">{bindings.map(([id,label])=><option key={id} value={id}>{label}</option>)}</select></label>}
      {selection.kind==='text'&&<label className="grid gap-1 text-xs font-semibold">Label text
       <textarea value={selection.text} maxLength={240} rows={3} onChange={e=>change(selection,{text:e.target.value})} className="resize-y rounded-lg border border-slate-300 p-2 text-sm"/></label>}
      {['text','field'].includes(selection.kind)&&<>
       <label className="grid gap-1 text-xs font-semibold">Font family <select value={selection.font} onChange={e=>change(selection,{font:e.target.value as Font})} className="rounded-lg border border-slate-300 p-2 text-sm"><option value="sans">Sans-serif</option><option value="serif">Serif</option><option value="mono">Monospace</option></select></label>
       <label className="grid gap-1 text-xs font-semibold">Font size (pt) <input type="number" min={5} max={32} step={.5} value={selection.size} onChange={e=>change(selection,{size:Number(e.target.value)})} className="rounded-lg border border-slate-300 p-2 text-sm"/></label>
       <div className="flex flex-wrap gap-1"><Tool icon={Bold} label="Bold" active={selection.bold} onClick={()=>change(selection,{bold:!selection.bold})}/><Tool icon={Italic} label="Italic" active={selection.italic} onClick={()=>change(selection,{italic:!selection.italic})}/><Tool icon={Underline} label="Underline" active={selection.underline} onClick={()=>change(selection,{underline:!selection.underline})}/></div>
       <label className="grid gap-1 text-xs font-semibold">Align
        <select value={selection.align} onChange={e=>change(selection,{align:e.target.value as Align})} className="rounded-lg border border-slate-300 p-2 text-sm"><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select></label></>}
      {selection.kind!=='image'&&selection.kind!=='qr'&&<label className="flex items-center justify-between gap-2 text-xs font-semibold">{selection.kind==='rect'?'Fill color':selection.kind==='line'?'Line color':'Text color'}
       <input type="color" value={selection.kind==='rect'?selection.fill:selection.kind==='line'?selection.stroke:selection.color} onChange={e=>change(selection,selection.kind==='rect'?{fill:e.target.value}:selection.kind==='line'?{stroke:e.target.value}:{color:e.target.value})} className="h-9 w-16 rounded-lg"/></label>}
      {['line','rect'].includes(selection.kind)&&<><label className="flex items-center justify-between gap-2 text-xs font-semibold">Stroke color <input type="color" value={selection.stroke} onChange={e=>change(selection,{stroke:e.target.value})} className="h-9 w-16"/></label>
       <label className="grid gap-1 text-xs font-semibold">Stroke width (pt)<input type="number" min={0} max={4} step={.1} value={selection.strokeWidth} onChange={e=>change(selection,{strokeWidth:Number(e.target.value)})} className="rounded-lg border border-slate-300 p-2"/></label></>}
      <div className="flex gap-2"><Button variant="outline" disabled={layers.length>=110} onClick={duplicate}><Copy size={14}/> Duplicate</Button><Button variant="outline" onClick={()=>{commit(layers.filter(l=>l.id!==selection.id));setSelected('');}}><Trash2 size={14}/> Remove</Button></div>
     </div>}
    </aside>
   </div>
   <div className="rounded-2xl border border-slate-200 bg-white p-4">
    <div className="flex flex-wrap items-end gap-3"><label className="grid min-w-[210px] flex-1 gap-1 text-xs font-semibold">Template name
     <input value={name} maxLength={70} onChange={e=>{setName(e.target.value);setDirty(true);}} className="rounded-lg border border-slate-300 p-2.5 text-sm"/></label>
     <span className="text-xs text-slate-500">{dirty?'Unsaved draft edits':'Draft saved'} · Template revision {library.revision}</span></div>
    {check.errors.length>0&&<div role="alert" className="mt-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-900"><strong>Publish blocked:</strong> {check.errors.slice(0,5).join(' · ')}</div>}
    {check.warnings.length>0&&<p className="mt-2 text-xs text-amber-700">{check.warnings.length} layout warnings to review before publishing.</p>}
    <div className="mt-4 flex flex-wrap gap-2"><Button variant="outline" disabled={busy||!dirty} onClick={()=>void save()}><Save size={16}/> Save Draft</Button>
     <Button variant="outline" disabled={busy||!sampleKey} onClick={()=>preview(false)}><Eye size={16}/> Preview Saved Draft</Button>
     <Button variant="outline" disabled={busy||!sampleKey} onClick={()=>preview(true)}><ArrowDownToLine size={16}/> PDF Proof</Button>
     <Button disabled={busy||dirty||check.errors.length>0} onClick={()=>void publish()}><Check size={16}/> Publish Design</Button>
     {!current.published&&<Button variant="outline" disabled={busy} onClick={()=>void deleteDraft()}><Trash2 size={16}/> Delete Draft</Button>}
    </div>
    {current.history.length>0&&<div className="mt-4 border-t border-slate-100 pt-4"><h3 className="text-sm font-bold">Published versions</h3>
     <div className="mt-2 flex flex-wrap gap-2">{[...current.history].reverse().map(v=><button type="button" disabled={busy} key={v.version} onClick={()=>void revert(v.version)} className="rounded-lg border border-slate-200 px-3 py-2 text-xs hover:bg-violet-50" title="Restore this version as a draft"><RotateCcw size={12} className="mr-1 inline"/> v{v.version} · {new Date(v.published_at).toLocaleDateString()}</button>)}</div>
    </div>}
   </div>
  </>}
  {!current&&!loading&&<p className="rounded-xl border border-dashed border-violet-200 p-6 text-sm text-slate-600">Create a design from the editable Classic or Print Clean vector starter. The original fixed templates remain available in Batch Print.</p>}
  <p className="text-xs text-slate-500">Template changes never alter saved LR facts. The preview uses sample values; print an actual PDF proof before publishing, especially for long names and multi-row consignments.</p>
 </section>;
}
