import * as React from 'react';
import {cn} from '@/lib/utils';
export function Input({className,...props}:React.ComponentProps<'input'>){return <input className={cn('h-10 min-w-0 rounded-lg border border-slate-300 bg-white px-3 text-sm focus-visible:outline-2 focus-visible:outline-[#0b6f5f]',className)} {...props}/>;}
