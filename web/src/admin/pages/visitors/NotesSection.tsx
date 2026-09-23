import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { z } from 'zod'
import type { Note } from '../../../shared/api-types'
import { useAddNote, useDeleteNote } from '../../api/visitors'
import { useCan } from '../../auth/context'
import { Button } from '../../components/Button'
import { Card } from '../../components/Card'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Field, FormAlert } from '../../components/Field'
import { Icon } from '../../components/Icon'
import { useToast } from '../../components/toast/context'
import { inputClass } from '../../components/styles'
import { errorMessage } from '../../lib/errors'
import { formatDateTime } from '../../lib/format'
import { applyServerErrors } from '../../lib/forms'
import { requiredText } from '../../lib/schemas'

const MAX = 2000
const schema = z.object({ body: requiredText('La note', MAX) })

function NoteForm({ visitorId }: { visitorId: number }) {
  const toast = useToast()
  const mutation = useAddNote(visitorId)
  const [failure, setFailure] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    reset,
    setError,
    control: formControl,
    formState: { errors },
  } = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema), defaultValues: { body: '' } })
  const length = useWatch({ control: formControl, name: 'body' }).length

  return (
    <form
      noValidate
      className="mb-6 flex flex-col gap-3"
      onSubmit={handleSubmit(async ({ body }) => {
        setFailure(null)
        try {
          await mutation.mutateAsync(body)
          reset({ body: '' })
          toast.success('Note ajoutée.')
        } catch (error) {
          setFailure(applyServerErrors(error, setError, ['body']))
        }
      })}
    >
      <FormAlert message={failure} />
      <Field
        label="Nouvelle note"
        hint={`${length} / ${MAX} caractères`}
        error={errors.body?.message}
      >
        {(control) => <textarea {...control} {...register('body')} rows={3} maxLength={MAX} className={inputClass} />}
      </Field>
      <div>
        <Button type="submit" pending={mutation.isPending} pendingLabel="Ajout…">
          <Icon name="plus" className="size-4" />
          Ajouter la note
        </Button>
      </div>
    </form>
  )
}

export function NotesSection({ visitorId, notes }: { visitorId: number; notes: Note[] }) {
  const canCreate = useCan('notes.create')
  const toast = useToast()
  const deleteNote = useDeleteNote(visitorId)
  const [toDelete, setToDelete] = useState<Note | null>(null)
  const sorted = [...notes].sort((a, b) => b.created_at.localeCompare(a.created_at))

  return (
    <Card title="Notes de suivi">
      {canCreate && <NoteForm visitorId={visitorId} />}
      {sorted.length === 0 ? (
        <p className="text-sm text-gray-700">Aucune note pour le moment.</p>
      ) : (
        <ol className="flex flex-col gap-3">
          {sorted.map((note) => (
            <li key={note.id} className="rounded-xl border border-gray-200 bg-gray-50 p-4">
              <p className="whitespace-pre-line break-words text-sm text-gray-900">{note.body}</p>
              <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                <p className="text-xs text-gray-700">
                  <span className="font-bold">{note.author?.name ?? 'Compte supprimé'}</span> ·{' '}
                  <time dateTime={note.created_at}>{formatDateTime(note.created_at)}</time>
                </p>
                {note.can_delete && (
                  <Button variant="ghost" size="sm" onClick={() => setToDelete(note)}>
                    <Icon name="trash" className="size-4" />
                    Supprimer <span className="sr-only">la note du {formatDateTime(note.created_at)}</span>
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ol>
      )}
      {toDelete && (
        <ConfirmDialog
          title="Supprimer cette note ?"
          confirmLabel="Supprimer la note"
          pendingLabel="Suppression…"
          tone="danger"
          pending={deleteNote.isPending}
          error={deleteNote.isError ? errorMessage(deleteNote.error) : null}
          onCancel={() => {
            deleteNote.reset()
            setToDelete(null)
          }}
          onConfirm={() =>
            deleteNote.mutate(toDelete.id, {
              onSuccess: () => {
                setToDelete(null)
                toast.success('Note supprimée.')
              },
            })
          }
        >
          <p>La note sera définitivement supprimée.</p>
          <blockquote className="line-clamp-3 border-l-4 border-gray-300 pl-3 text-gray-700">{toDelete.body}</blockquote>
        </ConfirmDialog>
      )}
    </Card>
  )
}
