import { clsx } from 'clsx'
import { useEffect, useId, useRef, type ReactNode, type RefObject } from 'react'
import { Icon } from './Icon'

interface DialogProps {
  title: string
  description?: ReactNode
  onClose: () => void
  /** Pendant un envoi : Échap et le bouton de fermeture sont neutralisés. */
  busy?: boolean
  /** Élément à focaliser à l'ouverture (sinon : premier élément focalisable). */
  initialFocusRef?: RefObject<HTMLElement | null>
  size?: 'sm' | 'md' | 'lg'
  role?: 'dialog' | 'alertdialog'
  children: ReactNode
}

function openModal(dialog: HTMLDialogElement) {
  if (typeof dialog.showModal === 'function') {
    if (!dialog.open) dialog.showModal()
  } else {
    dialog.setAttribute('open', '')
  }
}

function closeModal(dialog: HTMLDialogElement) {
  if (typeof dialog.close === 'function') {
    if (dialog.open) dialog.close()
  } else {
    dialog.removeAttribute('open')
  }
}

/**
 * Dialogue modal natif (`<dialog>` + `showModal()`) : piège de focus, Échap et
 * inertie du reste de la page fournis par le navigateur. Monté = ouvert.
 */
export function Dialog({
  title,
  description,
  onClose,
  busy = false,
  initialFocusRef,
  size = 'md',
  role = 'dialog',
  children,
}: DialogProps) {
  const ref = useRef<HTMLDialogElement>(null)
  const titleId = useId()
  const descriptionId = useId()
  const busyRef = useRef(busy)
  const onCloseRef = useRef(onClose)

  useEffect(() => {
    busyRef.current = busy
    onCloseRef.current = onClose
  })

  useEffect(() => {
    const dialog = ref.current
    if (!dialog) return
    const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null
    openModal(dialog)
    initialFocusRef?.current?.focus()
    return () => {
      closeModal(dialog)
      if (previouslyFocused?.isConnected) previouslyFocused.focus()
    }
  }, [initialFocusRef])

  return (
    <dialog
      ref={ref}
      role={role === 'alertdialog' ? 'alertdialog' : undefined}
      aria-labelledby={titleId}
      aria-describedby={description ? descriptionId : undefined}
      aria-modal="true"
      onCancel={(event) => {
        // Échap : fermeture pilotée par React (sauf pendant un envoi).
        event.preventDefault()
        if (!busyRef.current) onCloseRef.current()
      }}
      onClose={() => {
        // Fermeture native non annulable : on synchronise l'état React.
        if (ref.current && !ref.current.open) onCloseRef.current()
      }}
      className={clsx(
        'm-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] overflow-y-auto rounded-2xl bg-white p-0 text-gray-900 shadow-2xl backdrop:bg-black/50',
        size === 'sm' && 'max-w-md',
        size === 'md' && 'max-w-lg',
        size === 'lg' && 'max-w-2xl',
      )}
    >
      <div className="flex items-start justify-between gap-4 border-b border-gray-200 px-5 py-4">
        <div className="flex flex-col gap-1">
          <h2 id={titleId} className="font-display text-lg font-bold text-church-purple-dk">
            {title}
          </h2>
          {description && (
            <p id={descriptionId} className="text-sm text-gray-700">
              {description}
            </p>
          )}
        </div>
        <button
          type="button"
          onClick={() => !busy && onClose()}
          disabled={busy}
          aria-label="Fermer"
          className="-mr-2 -mt-1 inline-flex size-11 items-center justify-center rounded-lg text-gray-700 hover:bg-gray-100 focus-visible:outline-2 focus-visible:outline-church-purple disabled:opacity-50"
        >
          <Icon name="close" />
        </button>
      </div>
      <div className="px-5 py-4">{children}</div>
    </dialog>
  )
}

export function DialogActions({ children }: { children: ReactNode }) {
  return <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">{children}</div>
}
