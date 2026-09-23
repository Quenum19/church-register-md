import type { ComponentPropsWithRef, ReactNode } from 'react'
import { buttonClass, type ButtonSize, type ButtonVariant } from './styles'
import { Spinner } from './Spinner'

interface ButtonProps extends ComponentPropsWithRef<'button'> {
  variant?: ButtonVariant
  size?: ButtonSize
  /** Affiche un indicateur et désactive le bouton (pas de double envoi). */
  pending?: boolean
  pendingLabel?: string
  children: ReactNode
}

export function Button({
  variant = 'primary',
  size = 'md',
  pending = false,
  pendingLabel,
  disabled,
  className,
  type = 'button',
  children,
  ...rest
}: ButtonProps) {
  return (
    <button
      {...rest}
      type={type}
      disabled={disabled || pending}
      aria-busy={pending || undefined}
      className={buttonClass(variant, size, className)}
    >
      {pending && <Spinner />}
      {pending && pendingLabel ? pendingLabel : children}
    </button>
  )
}
