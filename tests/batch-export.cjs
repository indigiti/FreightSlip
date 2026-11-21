'use strict';
// Exercise the actual TypeScript export module under Node, with an in-memory
// PHP-equivalent PDF endpoint. No Cloudways account or demo LR data required.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const Module=require('node:module');
const ts=require('typescript');
const {unzipSync}=require('fflate');

const filename=path.resolve(__dirname,'../lib/batch-export.ts');
const source=fs.readFileSync(filename,'utf8');
const transpiled=ts.transpileModule(source,{compilerOptions:{
 module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022
}}).outputText;
const mod=new Module(filename,module);
mod.filename=filename;mod.paths=module.paths;
const normalRequire=mod.require.bind(mod);
mod.require=(name)=>name==='./api'
 ?{url:(action,params)=>'/api/index.php?'+new URLSearchParams({action,...params}).toString()}
 :normalRequire(name);
mod._compile(transpiled,filename);
const {PDF_BATCH_SIZE,splitPdfBatches,exportPdfBatchesZip}=mod.exports;
const ids=Array.from({length:1110},(_,i)=>'LR-'+String(i+1).padStart(5,'0'));
const mockPdf=(keys)=>new TextEncoder().encode('%PDF-1.4\\n'+keys.join('\\n'));
async function run(){
 assert.equal(PDF_BATCH_SIZE,100);
 assert.deepEqual(splitPdfBatches(ids).map(v=>v.length),Array(11).fill(100).concat([10]));
 assert.equal(splitPdfBatches(ids.slice(0,100)).length,1);
 assert.throws(()=>splitPdfBatches(ids.slice(0,101),101),/maximum PDF batch/);
 assert.throws(()=>splitPdfBatches(['LR-1','LR-1']),/Duplicate/);
 const calls=[],progress=[];
 const controller=new AbortController();
 const zip=await exportPdfBatchesZip(ids,'classic',false,{
  signal:controller.signal,
  onProgress:p=>progress.push(p),
  fetcher:async (input,options)=>{
   const query=new URL(String(input),'https://local.test').searchParams;
   const keys=query.get('keys').split(',');
   assert.equal(query.get('action'),'pdf');
   assert.equal(query.get('layout'),'classic');
   assert.equal(query.get('qr'),'0');
   assert.equal(options.credentials,'same-origin');
   assert.ok(keys.length>=1&&keys.length<=100);
   calls.push(keys);
   return new Response(mockPdf(keys),{status:200,headers:{'content-type':'application/pdf'}});
  }
 });
 assert.equal(calls.length,12);
 assert.equal(calls.flat().join(','),ids.join(','));
 assert.equal(progress.length,12);
 assert.deepEqual(progress[11],{completed:12,total:12,receipts:1110});
 const entries=unzipSync(new Uint8Array(await zip.arrayBuffer()));
 assert.equal(Object.keys(entries).length,12);
 assert.ok(Object.prototype.hasOwnProperty.call(entries,'LRs-00001-00100.pdf'));
 assert.ok(Object.prototype.hasOwnProperty.call(entries,'LRs-01101-01110.pdf'));
 assert.ok(new TextDecoder().decode(entries['LRs-01101-01110.pdf']).includes(ids[1109]));
 assert.equal(zip.type,'application/zip');

 let attempts=0;
 await assert.rejects(()=>exportPdfBatchesZip(ids,'clean',true,{
  signal:new AbortController().signal,onProgress:()=>{},
  fetcher:async()=>{
   attempts++;
   if(attempts===2)return new Response(JSON.stringify({error:'Receipt not found or unavailable.'}),{status:404,headers:{'content-type':'application/json'}});
   return new Response(mockPdf(['okay']),{status:200});
  }
 }),/no longer accessible/);
 assert.equal(attempts,2,'Stop on the first rejected workspace batch');
 const aborted=new AbortController();let completed=0;
 await assert.rejects(()=>exportPdfBatchesZip(ids,'classic',true,{
  signal:aborted.signal,onProgress:()=>{
   completed++;if(completed===1)aborted.abort();
  },
  fetcher:async()=>new Response(mockPdf(['okay']),{status:200})
 }),/canceled/i);
 assert.equal(completed,1);

 let responses=0;
 await assert.rejects(()=>exportPdfBatchesZip(ids,'classic',false,{
  signal:new AbortController().signal,onProgress:()=>{},
  fetcher:async()=>{responses++;return new Response('<html>login</html>',{status:200,headers:{'content-type':'text/html'}});}
 }),/invalid PDF/);
 assert.equal(responses,1);
 console.log('Bulk PDF tests passed: 1110 LRs -> 12 PDFs, 100-key limits, ZIP integrity, permissions, cancellation.');
}
run().catch(error=>{console.error(error);process.exitCode=1;});
