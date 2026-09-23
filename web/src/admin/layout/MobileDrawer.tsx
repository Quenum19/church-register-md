import { useEffect, useRef, type ReactNode } from 'react'
import { Icon } from '../components/Icon'

/** Tiroir de navigation mobile : `<dialog>` modal natif (focus piégé, Échap). Monté = ouvert. */
export function MobileDrawer({ id, onClose, children }: { id: string; onClose: () => void; children: ReactNode }) {
  const ref = useRef<HTMLDialogElement>(null)
  const onCloseRef = useRef(onClose)
  useEffect(() => {
    onCloseRef.current = onClose
  })

  useEffect(() => {
    const dialog = ref.current
    if (!dialog) return
    const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null
    if (typeof dialog.showModal === 'function') {
      if (!dialog.open) dialog.showModal()
    } else {
      dialog.setAttribute('open', '')
    }
    return () => {
      if (typeof dialog.close === 'function') {
        if (dialog.open) dialog.close()
      } else {
        dialog.removeAttribute('open')
      }
      if (previouslyFocused?.isConnected) previouslyFocused.focus()
    }
  }, [])

  return (
    <dialog
      ref={ref}
      id={id}
      aria-label="Menu de navigation"
      onCancel={(event) => {
        event.preventDefault()
        onCloseRef.current()
      }}
      onClose={() => {
        if (ref.current && !ref.current.open) onCloseRef.current()
      }}
      className="fixed inset-y-0 left-0 m-0 h-dvh max-h-dvh w-72 max-w-[85vw] bg-church-purple-dk p-0 text-white shadow-2xl backdrop:bg-black/50 lg:hidden"
    >
      <button
        type="button"
        onClick={onClose}
        aria-label="Fermer le menu"
        className="absolute right-2 top-3 z-10 inline-flex size-11 items-center justify-center rounded-lg text-white hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-church-gold-lt"
      >
        <Icon name="close" />
      </button>
      {children}
    </dialog>
  )
}
