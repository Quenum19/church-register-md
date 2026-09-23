import { useEffect, useId, useRef, useState } from 'react'
import { Link } from 'react-router'
import { ROLE_LABELS } from '../../shared/domain'
import { useAuth } from '../auth/context'
import { Icon } from '../components/Icon'
import { Spinner } from '../components/Spinner'
import { useToast } from '../components/toast/context'
import { errorMessage } from '../lib/errors'

function initials(name: string): string {
  return (
    name
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? '')
      .join('') || '?'
  )
}

/** Menu utilisateur (motif « disclosure ») : profil et déconnexion. */
export function UserMenu() {
  const { user, logout } = useAuth()
  const toast = useToast()
  const [open, setOpen] = useState(false)
  const [pending, setPending] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)
  const buttonRef = useRef<HTMLButtonElement>(null)
  const panelId = useId()

  useEffect(() => {
    if (!open) return
    const onPointer = (event: PointerEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false)
    }
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setOpen(false)
        buttonRef.current?.focus()
      }
    }
    document.addEventListener('pointerdown', onPointer)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('pointerdown', onPointer)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  if (!user) return null

  const handleLogout = async () => {
    setPending(true)
    try {
      await logout()
    } catch (error) {
      setPending(false)
      toast.error(`Déconnexion impossible : ${errorMessage(error)}`)
    }
  }

  return (
    <div
      ref={containerRef}
      className="relative"
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setOpen(false)
      }}
    >
      <button
        ref={buttonRef}
        type="button"
        aria-expanded={open}
        aria-controls={panelId}
        onClick={() => setOpen((v) => !v)}
        className="flex min-h-11 items-center gap-2 rounded-full py-1 pl-1 pr-3 text-sm font-bold text-gray-900 hover:bg-gray-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-church-purple"
      >
        <span aria-hidden="true" className="grid size-9 place-items-center rounded-full bg-church-purple text-xs font-bold text-white">
          {initials(user.name)}
        </span>
        <span className="max-w-40 truncate">{user.name}</span>
        <span className="sr-only">, menu du compte</span>
      </button>
      {open && (
        <div
          id={panelId}
          className="absolute right-0 top-full z-40 mt-2 w-72 rounded-xl border border-gray-200 bg-white p-2 shadow-xl"
        >
          <div className="border-b border-gray-200 px-3 pb-3 pt-2">
            <p className="truncate font-bold text-gray-900">{user.name}</p>
            <p className="truncate text-sm text-gray-700">{user.email}</p>
            <p className="mt-1 text-xs font-bold text-church-purple">{ROLE_LABELS[user.role] ?? user.role}</p>
          </div>
          <ul className="mt-2 flex flex-col gap-1">
            <li>
              <Link
                to="/admin/parametres"
                onClick={() => setOpen(false)}
                className="flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm font-bold text-gray-900 hover:bg-church-purple-xl focus-visible:outline-2 focus-visible:outline-church-purple"
              >
                <Icon name="user" />
                Mon compte
              </Link>
            </li>
            <li>
              <button
                type="button"
                onClick={handleLogout}
                disabled={pending}
                className="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-left text-sm font-bold text-red-800 hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-red-700 disabled:opacity-60"
              >
                {pending ? <Spinner /> : <Icon name="logout" />}
                {pending ? 'Déconnexion…' : 'Se déconnecter'}
              </button>
            </li>
          </ul>
        </div>
      )}
    </div>
  )
}
