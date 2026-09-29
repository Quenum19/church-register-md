import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { z } from 'zod'
import type { ChurchEvent, EventInput } from '../../../shared/api-types'
import { useCreateEvent, useUpdateEvent } from '../../api/events'
import { Button } from '../../components/Button'
import { Dialog, DialogActions } from '../../components/Dialog'
import { CheckboxField, Field, FormAlert } from '../../components/Field'
import { useToast } from '../../components/toast/context'
import { checkboxClass, inputClass } from '../../components/styles'
import { applyServerErrors } from '../../lib/forms'
import { formatNumber } from '../../lib/format'
import { requiredText } from '../../lib/schemas'
import { slugField, slugify, SLUG_HINT, SLUG_MAX_LENGTH } from '../../lib/slug'

const eventSchema = z.object({
  name: requiredText('Le nom', 100),
  slug: slugField,
  event_date: z
    .string()
    .trim()
    .refine((value) => value === '' || /^\d{4}-\d{2}-\d{2}$/.test(value), 'Saisissez une date valide (jj/mm/aaaa).'),
  active: z.boolean(),
})
type EventValues = z.infer<typeof eventSchema>

const FIELDS = ['name', 'slug', 'event_date', 'active'] as const

export function EventDialog({ event, onClose }: { event: ChurchEvent | null; onClose: () => void }) {
  const toast = useToast()
  const create = useCreateEvent()
  const update = useUpdateEvent()
  const mutation = event ? update : create
  const [failure, setFailure] = useState<string | null>(null)
  // En création, le lien suit le nom tant que le responsable ne l'a pas écrit lui-même.
  const [slugEdited, setSlugEdited] = useState(event !== null)
  const {
    register,
    handleSubmit,
    setError,
    setValue,
    control: formControl,
    formState: { errors, dirtyFields },
  } = useForm<EventValues>({
    resolver: zodResolver(eventSchema),
    defaultValues: {
      name: event?.name ?? '',
      slug: event?.slug ?? '',
      event_date: event?.event_date ?? '',
      active: event?.active ?? true,
    },
  })

  const name = useWatch({ control: formControl, name: 'name' })
  const slug = useWatch({ control: formControl, name: 'slug' })

  useEffect(() => {
    if (slugEdited) return
    setValue('slug', slugify(name ?? ''))
  }, [name, slugEdited, setValue])

  const registrations = event ? event.visitors_count : 0
  const slugChanged = event !== null && slug !== event.slug
  const slugInput = register('slug', { onChange: () => setSlugEdited(true) })

  const submit = handleSubmit(async (values) => {
    setFailure(null)
    const body: EventInput = {
      name: values.name,
      slug: values.slug,
      event_date: values.event_date === '' ? null : values.event_date,
      active: values.active,
    }
    try {
      if (event) {
        const changes: Partial<EventInput> & { id: number } = { id: event.id }
        if (dirtyFields.name) changes.name = body.name
        if (dirtyFields.slug) changes.slug = body.slug
        if (dirtyFields.event_date) changes.event_date = body.event_date
        if (dirtyFields.active) changes.active = body.active
        if (Object.keys(changes).length === 1) {
          onClose()
          return
        }
        await update.mutateAsync(changes)
        toast.success(`Événement « ${body.name} » modifié.`)
      } else {
        await create.mutateAsync(body)
        toast.success(`Événement « ${body.name} » créé.`)
      }
      onClose()
    } catch (error) {
      setFailure(applyServerErrors(error, setError, FIELDS))
    }
  })

  return (
    <Dialog
      title={event ? 'Modifier l’événement' : 'Créer un événement'}
      description={
        event
          ? 'Le lien et le QR code de cet événement dépendent de son adresse.'
          : 'L’événement reçoit son propre lien d’inscription et son QR code, distincts du lien habituel.'
      }
      onClose={onClose}
      busy={mutation.isPending}
    >
      <form noValidate className="flex flex-col gap-4" onSubmit={submit}>
        <FormAlert message={failure} />
        <Field label="Nom de l’événement" hint="Ex. « Évangélisation du 4 octobre »." error={errors.name?.message}>
          {(control) => <input {...control} {...register('name')} autoComplete="off" className={inputClass} />}
        </Field>
        <Field
          label="Adresse du lien"
          hint={
            <>
              {SLUG_HINT} Lien public : <span className="font-bold text-gray-800">/e/{slug || '…'}</span>
            </>
          }
          error={errors.slug?.message}
        >
          {(control) => (
            <input
              {...control}
              {...slugInput}
              autoComplete="off"
              spellCheck={false}
              maxLength={SLUG_MAX_LENGTH}
              className={inputClass}
            />
          )}
        </Field>
        {slugChanged && registrations > 0 && (
          <FormAlert
            tone="warning"
            message={
              <>
                <strong>Attention :</strong> {formatNumber(registrations)} personne{registrations > 1 ? 's se sont' : ' s’est'}{' '}
                déjà inscrite{registrations > 1 ? 's' : ''} par le lien <span className="font-bold">/e/{event.slug}</span>. En
                changeant cette adresse, les QR codes et les affiches déjà imprimés cesseront de fonctionner : il faudra les
                réimprimer.
              </>
            }
          />
        )}
        <Field label="Date du culte" optional hint="Laissez vide si la date n’est pas encore fixée." error={errors.event_date?.message}>
          {(control) => <input {...control} {...register('event_date')} type="date" className={inputClass} />}
        </Field>
        <CheckboxField
          label="Lien actif"
          hint="Un lien inactif n’accepte plus de nouvelles inscriptions ; les visites déjà enregistrées sont conservées."
        >
          {(control) => <input {...control} {...register('active')} type="checkbox" className={checkboxClass} />}
        </CheckboxField>
        <DialogActions>
          <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>
            Annuler
          </Button>
          <Button type="submit" pending={mutation.isPending} pendingLabel="Enregistrement…">
            {event ? 'Enregistrer' : 'Créer l’événement'}
          </Button>
        </DialogActions>
      </form>
    </Dialog>
  )
}
