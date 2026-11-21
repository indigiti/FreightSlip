// Client-side batch ZIP assembly. Individual 100-LR PDFs are generated and
// permission-checked by the existing PHP endpoint; no long-running PHP ZIP job.
import {Zip,ZipPassThrough} from 'fflate';
import {url} from './api';

export const PDF_BATCH_SIZE=100;
export type ExportProgress={completed:number;total:number;receipts:number};
export type ExportOptions={
 signal:AbortSignal;
 onProgress:(progress:ExportProgress)=>void;
 fetcher?:typeof fetch;
};

export function splitPdfBatches(keys:readonly string[],batchSize=PDF_BATCH_SIZE):string[][]{
 if(!Number.isSafeInteger(batchSize)||batchSize<1||batchSize>PDF_BATCH_SIZE)
  throw new Error('The maximum PDF batch is 100 LRs.');
 if(!keys.length)throw new Error('Select at least one LR.');
 if(new Set(keys).size!==keys.length)throw new Error('Duplicate LR selection is not permitted.');
 if(keys.some(key=>!key||key.includes(',')))throw new Error('Invalid LR selection.');
 const batches:string[][]=[];
 for(let i=0;i<keys.length;i+=batchSize)batches.push(keys.slice(i,i+batchSize));
 return batches;
}

function pdfError(status:number,body:string):Error{
 let detail='';
 try{const data=JSON.parse(body);if(typeof data?.error==='string')detail=data.error;}catch{}
 if(status===401)return new Error('Session expired. Sign in again before exporting.');
 if(status===403||status===404)return new Error('An LR or workspace is no longer accessible. Refresh the register and retry.');
 return new Error(detail||`PDF batch failed (HTTP ${status}). No incomplete ZIP was downloaded.`);
}

export async function exportPdfBatchesZip(
 keys:readonly string[],layout:string,includeQr:boolean,options:ExportOptions
):Promise<Blob>{
 const batches=splitPdfBatches(keys);
 if(batches.length<2)throw new Error('Use Download PDF for selections of 100 LRs or fewer.');
 if(!layout||layout.length>140)throw new Error('Select a valid print design.');
 const fetcher=options.fetcher??fetch;
 return new Promise<Blob>((resolve,reject)=>{
  const parts:Uint8Array[]=[];
  let settled=false;
  const fail=(error:unknown)=>{
   if(settled)return;
   settled=true;
   reject(error instanceof Error?error:new Error('Could not assemble the PDF ZIP.'));
  };
  const archive=new Zip((err,chunk,final)=>{
   if(err){fail(err);return;}
   if(chunk.length)parts.push(chunk);
   if(final&&!settled){
    settled=true;
    resolve(new Blob(parts as BlobPart[],{type:'application/zip'}));
   }
  });
  const abort=()=>{archive.terminate();fail(new DOMException('Bulk PDF export canceled.','AbortError'));};
  if(options.signal.aborted){abort();return;}
  options.signal.addEventListener('abort',abort,{once:true});
  void (async()=>{
   for(let i=0;i<batches.length;i++){
    if(options.signal.aborted)throw new DOMException('Bulk PDF export canceled.','AbortError');
    const current=batches[i];
    const response=await fetcher(url('pdf',{
     layout,qr:includeQr?'1':'0',keys:current.join(',')
    }),{credentials:'same-origin',cache:'no-store',signal:options.signal,headers:{Accept:'application/pdf'}});
    if(!response.ok)throw pdfError(response.status,await response.text());
    const data=new Uint8Array(await response.arrayBuffer());
    if(data.length<5||data[0]!==37||data[1]!==80||data[2]!==68||data[3]!==70||data[4]!==45)
     throw new Error(`PDF batch ${i+1} returned an invalid PDF. The ZIP was not downloaded.`);
    // PDFs are already compressed: store them without a second compression pass.
    const start=String(i*PDF_BATCH_SIZE+1).padStart(5,'0');
    const end=String(i*PDF_BATCH_SIZE+current.length).padStart(5,'0');
    const entry=new ZipPassThrough(`LRs-${start}-${end}.pdf`);
    archive.add(entry);
    entry.push(data,true);
    options.onProgress({completed:i+1,total:batches.length,receipts:Math.min((i+1)*PDF_BATCH_SIZE,keys.length)});
   }
   archive.end();
  })().catch(error=>{archive.terminate();fail(error);})
   .finally(()=>options.signal.removeEventListener('abort',abort));
 });
}
