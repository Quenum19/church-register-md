// Champs accessibles : label lié (htmlFor/id), indication et erreur reliées par
// aria-describedby, aria-invalid. Compatibles avec react-hook-form.
// Champs propres aux formulaires de visite (choix, cases, zones de texte) : visit/FormFields.tsx.

import { clsx } from 'clsx'
import type { InputHTMLAttributes, ReactNode, Ref, SelectHTMLAttributes } from 'react'
import { COUNTRIES } from '../../shared/domain'
import { describedBy } from './aria'
import { hintBase, inputBase, labelBase } from './classes'

export function FieldError({ id, message }: { id?: string; message?: string | null }) {
  if (!message) return null
  return (
    <p id={id} className="mt-1.5 flex items-start gap-1.5 text-sm font-bold text-red-700">
      <span aria-hidden="true">⚠</span>
      <span>
        <span className="sr-only">Erreur : </span>
        {message}
      </span>
    </p>
  )
}

interface LabelProps {
  htmlFor: string
  children: ReactNode
  optional?: boolean
}

export function FieldLabel({ htmlFor, children, optional }: LabelProps) {
  return (
    <label htmlFor={htmlFor} className={labelBase}>
      {children}
      {optional && <span className="font-normal text-gray-700"> (facultatif)</span>}
    </label>
  )
}

export interface CommonProps {
  id: string
  label: ReactNode
  hint?: ReactNode
  error?: string
  optional?: boolean
}

type TextFieldProps = CommonProps &
  Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> & {
    ref?: Ref<HTMLInputElement>
    /** Élément affiché à gauche du champ (indicatif téléphonique), masqué aux lecteurs d'écran. */
    dialPrefix?: string
  }

export function TextField({ id, label, hint, error, optional, dialPrefix, className, ref, ...rest }: TextFieldProps) {
  const hintId = hint ? `${id}-hint` : undefined
  const errorId = error ? `${id}-error` : undefined
  const input = (
    <input
      id={id}
      ref={ref}
      aria-invalid={error ? true : undefined}
      aria-required={optional ? undefined : true}
      aria-describedby={describedBy(hintId, errorId)}
      className={clsx(inputBase, dialPrefix && 'rounded-l-none', className)}
      {...rest}
    />
  )
  return (
    <div className="flex flex-col gap-1.5">
      <FieldLabel htmlFor={id} optional={optional}>
        {label}
      </FieldLabel>
      {hint && (
        <p id={hintId} className={hintBase}>
          {hint}
        </p>
      )}
      {dialPrefix ? (
        <div className="flex">
          <span
            aria-hidden="true"
            className="inline-flex shrink-0 items-center rounded-l-xl border border-r-0 border-gray-500 bg-church-purple-xl px-3 font-bold text-church-purple-dk"
          >
            {dialPrefix}
          </span>
          {input}
        </div>
      ) : (
        input
      )}
      <FieldError id={errorId} message={error} />
    </div>
  )
}

type SelectFieldProps = CommonProps &
  Omit<SelectHTMLAttributes<HTMLSelectElement>, 'id'> & { ref?: Ref<HTMLSelectElement>; children: ReactNode }

/** <select> natif stylé : fonctionne partout, jamais de liste rognée. */
export function SelectField({ id, label, hint, error, optional, className, ref, children, ...rest }: SelectFieldProps) {
  const hintId = hint ? `${id}-hint` : undefined
  const errorId = error ? `${id}-error` : undefined
  return (
    <div className="flex flex-col gap-1.5">
      <FieldLabel htmlFor={id} optional={optional}>
        {label}
      </FieldLabel>
      {hint && (
        <p id={hintId} className={hintBase}>
          {hint}
        </p>
      )}
      <div className="relative">
        <select
          id={id}
          ref={ref}
          aria-invalid={error ? true : undefined}
          aria-describedby={describedBy(hintId, errorId)}
          className={clsx(inputBase, 'cursor-pointer appearance-none pr-11', className)}
          {...rest}
        >
          {children}
        </select>
        <svg
          aria-hidden="true"
          viewBox="0 0 20 20"
          className="pointer-events-none absolute top-1/2 right-4 size-5 -translate-y-1/2 fill-church-purple"
        >
          <path d="M5.3 7.3a1 1 0 0 1 1.4 0L10 10.6l3.3-3.3a1 1 0 1 1 1.4 1.4l-4 4a1 1 0 0 1-1.4 0l-4-4a1 1 0 0 1 0-1.4Z" />
        </svg>
      </div>
      <FieldError id={errorId} message={error} />
    </div>
  )
}

type CountrySelectProps = Omit<SelectFieldProps, 'children'>

/** Sélecteur de pays natif (liste du contrat, Côte d'Ivoire en tête). */
export function CountrySelect(props: CountrySelectProps) {
  return (
    <SelectField autoComplete="off" {...props}>
      {COUNTRIES.map((c) => (
        <option key={c.code} value={c.code}>
          {c.code === 'OTHER' ? 'Autre pays (numéro avec indicatif)' : `${c.name} (${c.dial})`}
        </option>
      ))}
    </SelectField>
  )
}
