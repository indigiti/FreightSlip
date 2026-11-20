import * as React from 'react';
import {Slot} from '@radix-ui/react-slot';
import {cva,type VariantProps} from 'class-variance-authority';
import {cn} from '@/lib/utils';
const variants=cva('inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-45 disabled:pointer-events-none', {variants:{variant:{default:'bg-[#5239ec] text-white hover:bg-[#4429d8]',outline:'border border-[#e0deec] bg-white hover:bg-[#f7f5ff] text-slate-800',ghost:'text-slate-700 hover:bg-slate-100'}},defaultVariants:{variant:'default'}});
export function Button({asChild,variant,className,...props}:React.ComponentProps<'button'>&VariantProps<typeof variants>&{asChild?:boolean}){const Comp=asChild?Slot:'button';return <Comp className={cn(variants({variant}),className)} {...props}/>;}
