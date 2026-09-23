import { useRef, type ReactNode } from 'react'
import { Button } from './Button'
import { Dialog, DialogActions } from './Dialog'
import { FormAlert } from './Field'

interface ConfirmDialogProps {
  title: string
  children: ReactNode
  confirmLabel: string
  pendingLabel?: string
  tone?: 'danger' | 'primary'
  pending?: boolean
  error?: string | null
  onConfirm: () => void
  onCancel: () => void
}

/** Confirmation d'une action sensible ; le focus initial est sur « Annuler ». */
export function ConfirmDialog({
  title,
  children,
  confirmLabel,
  pendingLabel,
  tone = 'primary',
  pending = false,
  error,
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  const cancelRef = useRef<HTMLButtonElement>(null)
  return (
    <Dialog title={title} onClose={onCancel} busy={pending} initialFocusRef={cancelRef} size="sm" role="alertdialog">
      <div className="flex flex-col gap-3 text-sm text-gray-800">{children}</div>
      {error && (
        <div className="mt-4">
          <FormAlert message={error} />
        </div>
      )}
      <DialogActions>
        <Button ref={cancelRef} variant="secondary" onClick={onCancel} disabled={pending}>
          Annuler
        </Button>
        <Button variant={tone === 'danger' ? 'danger' : 'primary'} onClick={onConfirm} pending={pending} pendingLabel={pendingLabel}>
          {confirmLabel}
        </Button>
      </DialogActions>
    </Dialog>
  )
}
