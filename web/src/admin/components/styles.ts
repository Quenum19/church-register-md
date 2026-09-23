// Classes Tailwind partagées (contrastes ≥ 4,5:1, cibles 44 px, focus visible).

import { clsx } from 'clsx'

export type ButtonVariant = 'primary' | 'secondary' | 'gold' | 'danger' | 'ghost'
export type ButtonSize = 'md' | 'sm'

const BUTTON_BASE =
  'inline-flex items-center justify-center gap-2 rounded-lg font-bold transition-colors ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-60 ' +
  'aria-disabled:cursor-not-allowed aria-disabled:opacity-60'

const BUTTON_VARIANTS: Record<ButtonVariant, string> = {
  primary:
    'bg-church-purple text-white hover:bg-church-purple-dk disabled:hover:bg-church-purple focus-visible:outline-church-purple',
  secondary:
    'border border-church-purple bg-white text-church-purple hover:bg-church-purple-xl disabled:hover:bg-white focus-visible:outline-church-purple',
  gold: 'bg-church-gold text-church-purple-dk hover:bg-church-gold-lt disabled:hover:bg-church-gold focus-visible:outline-church-purple-dk',
  danger: 'bg-red-700 text-white hover:bg-red-800 disabled:hover:bg-red-700 focus-visible:outline-red-700',
  ghost: 'text-church-purple hover:bg-church-purple-xl disabled:hover:bg-transparent focus-visible:outline-church-purple',
}

const BUTTON_SIZES: Record<ButtonSize, string> = {
  md: 'min-h-11 px-4 py-2 text-sm',
  sm: 'min-h-11 px-3 py-1.5 text-sm sm:min-h-9',
}

export function buttonClass(variant: ButtonVariant = 'primary', size: ButtonSize = 'md', extra?: string): string {
  return clsx(BUTTON_BASE, BUTTON_VARIANTS[variant], BUTTON_SIZES[size], extra)
}

export const inputClass =
  'block w-full min-h-11 rounded-lg border border-gray-500 bg-white px-3 py-2 text-base text-gray-900 ' +
  'placeholder:text-gray-500 focus:border-church-purple focus:outline-2 focus:outline-offset-1 focus:outline-church-purple ' +
  'aria-[invalid=true]:border-red-700 disabled:bg-gray-100 disabled:text-gray-700 read-only:bg-gray-50'

export const checkboxClass =
  'size-5 shrink-0 rounded border-gray-500 accent-church-purple focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-church-purple'

export const cardClass = 'rounded-2xl border border-gray-200 bg-white shadow-sm'

export const linkClass =
  'font-bold text-church-purple underline decoration-church-purple/40 underline-offset-2 hover:decoration-church-purple ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-church-purple rounded-sm'

export const tableHeadClass = 'px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-gray-700'
export const tableCellClass = 'px-4 py-3 align-top text-sm text-gray-900'
