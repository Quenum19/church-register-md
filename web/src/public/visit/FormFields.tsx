// Champs propres aux formulaires de visite : zones de texte, cases à cocher et groupes
// de choix natifs (<fieldset>/<legend>). Hors du bundle initial.

import { clsx } from 'clsx'
import type { InputHTMLAttributes, ReactNode, Ref, TextareaHTMLAttributes } from 'react'
import { hintBase, inputBase, labelBase } from '../ui/classes'
import { describedBy } from '../ui/aria'
import { FieldError, FieldLabel, type CommonProps } from '../ui/Field'

const tileBase =
  'flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-gray-500 bg-white px-4 py-3 text-gray-900 transition ' +
  'hover:border-church-purple has-checked:border-church-purple has-checked:bg-church-purple-xl has-checked:font-bold ' +
  'has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-church-purple'

const controlBase = 'size-5 shrink-0 cursor-pointer accent-church-purple'

type TextAreaProps = CommonProps &
  Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'id'> & { ref?: Ref<HTMLTextAreaElement> }

export function TextArea({ id, label, hint, error, optional, className, ref, rows = 3, ...rest }: TextAreaProps) {
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
      <textarea
        id={id}
        ref={ref}
        rows={rows}
        aria-invalid={error ? true : undefined}
        aria-required={optional ? undefined : true}
        aria-describedby={describedBy(hintId, errorId)}
        className={clsx(inputBase, 'resize-y', className)}
        {...rest}
      />
      <FieldError id={errorId} message={error} />
    </div>
  )
}

type CheckboxProps = Omit<CommonProps, 'optional'> &
  Omit<InputHTMLAttributes<HTMLInputElement>, 'id' | 'type'> & { ref?: Ref<HTMLInputElement>; required?: boolean }

/** Case à cocher isolée (consentement, options). */
export function Checkbox({ id, label, hint, error, required, ref, ...rest }: CheckboxProps) {
  const hintId = hint ? `${id}-hint` : undefined
  const errorId = error ? `${id}-error` : undefined
  return (
    <div>
      <label htmlFor={id} className={clsx(tileBase, 'items-start has-checked:font-normal')}>
        <input
          id={id}
          ref={ref}
          type="checkbox"
          aria-invalid={error ? true : undefined}
          aria-required={required ? true : undefined}
          aria-describedby={describedBy(hintId, errorId)}
          className={clsx(controlBase, 'mt-0.5')}
          {...rest}
        />
        <span>{label}</span>
      </label>
      {hint && (
        <p id={hintId} className={clsx(hintBase, 'mt-1.5')}>
          {hint}
        </p>
      )}
      <FieldError id={errorId} message={error} />
    </div>
  )
}

interface ChoiceGroupProps {
  id: string
  legend: ReactNode
  hint?: ReactNode
  error?: string
  children: ReactNode
}

/** Groupe de boutons radio ou de cases à cocher natifs, présentés en tuiles. */
export function ChoiceGroup({ id, legend, hint, error, children }: ChoiceGroupProps) {
  const hintId = hint ? `${id}-hint` : undefined
  const errorId = error ? `${id}-error` : undefined
  return (
    <fieldset id={id} aria-describedby={describedBy(hintId, errorId)} className="min-w-0">
      <legend className={clsx(labelBase, 'mb-1.5')}>{legend}</legend>
      {hint && (
        <p id={hintId} className={clsx(hintBase, 'mb-2')}>
          {hint}
        </p>
      )}
      <div className="grid gap-2">{children}</div>
      <FieldError id={errorId} message={error} />
    </fieldset>
  )
}

type ChoiceOptionProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'id' | 'type'> & {
  id: string
  type: 'radio' | 'checkbox'
  label: ReactNode
  invalid?: boolean
  ref?: Ref<HTMLInputElement>
}

export function ChoiceOption({ id, type, label, invalid, ref, ...rest }: ChoiceOptionProps) {
  return (
    <label htmlFor={id} className={tileBase}>
      <input
        id={id}
        ref={ref}
        type={type}
        aria-invalid={invalid ? true : undefined}
        className={controlBase}
        {...rest}
      />
      <span>{label}</span>
    </label>
  )
}
