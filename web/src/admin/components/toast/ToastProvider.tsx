import { clsx } from 'clsx'
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { Icon } from '../Icon'
import { ToastContext, type ToastApi, type ToastTone } from './context'

interface ToastItem {
  id: number
  tone: ToastTone
  message: string
}

const DURATION: Record<ToastTone, number> = { success: 6000, info: 6000, error: 10000 }

/**
 * Notifications non bloquantes. Les régions `aria-live` existent dès le montage
 * (condition pour que les lecteurs d'écran annoncent les messages ajoutés).
 */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<ToastItem[]>([])
  const nextId = useRef(1)
  const timers = useRef(new Map<number, ReturnType<typeof setTimeout>>())

  const dismiss = useCallback((id: number) => {
    setToasts((list) => list.filter((t) => t.id !== id))
    const timer = timers.current.get(id)
    if (timer) clearTimeout(timer)
    timers.current.delete(id)
  }, [])

  const push = useCallback(
    (tone: ToastTone, message: string) => {
      const id = nextId.current++
      setToasts((list) => [...list.slice(-3), { id, tone, message }])
      timers.current.set(
        id,
        setTimeout(() => dismiss(id), DURATION[tone]),
      )
    },
    [dismiss],
  )

  useEffect(() => {
    const pending = timers.current
    return () => {
      for (const timer of pending.values()) clearTimeout(timer)
      pending.clear()
    }
  }, [])

  const api = useMemo<ToastApi>(
    () => ({
      success: (message) => push('success', message),
      error: (message) => push('error', message),
      info: (message) => push('info', message),
    }),
    [push],
  )

  const render = (tone: 'polite' | 'assertive') =>
    toasts
      .filter((t) => (tone === 'assertive') === (t.tone === 'error'))
      .map((t) => (
        <div
          key={t.id}
          className={clsx(
            'pointer-events-auto flex items-start gap-3 rounded-xl border px-4 py-3 text-sm shadow-lg',
            t.tone === 'success' && 'border-green-300 bg-green-50 text-green-900',
            t.tone === 'info' && 'border-church-purple/30 bg-white text-church-purple-dk',
            t.tone === 'error' && 'border-red-300 bg-red-50 text-red-900',
          )}
        >
          <p className="flex-1 pt-0.5">{t.message}</p>
          <button
            type="button"
            onClick={() => dismiss(t.id)}
            aria-label="Fermer la notification"
            className="-m-1 inline-flex size-8 items-center justify-center rounded-md hover:bg-black/5 focus-visible:outline-2 focus-visible:outline-church-purple"
          >
            <Icon name="close" className="size-4" />
          </button>
        </div>
      ))

  return (
    <ToastContext value={api}>
      {children}
      <div className="pointer-events-none fixed inset-x-4 bottom-4 z-50 flex flex-col items-stretch gap-2 sm:left-auto sm:w-96 print:hidden">
        <div aria-live="polite" aria-atomic="false" className="flex flex-col gap-2">
          {render('polite')}
        </div>
        <div aria-live="assertive" aria-atomic="false" className="flex flex-col gap-2">
          {render('assertive')}
        </div>
      </div>
    </ToastContext>
  )
}
