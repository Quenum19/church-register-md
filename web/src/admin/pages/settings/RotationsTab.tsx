import { zodResolver } from '@hookform/resolvers/zod'
import { clsx } from 'clsx'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import type { Family, Rotation } from '../../../shared/api-types'
import { useFamilies, useRotations, useUpdateRotation } from '../../api/settings'
import { useCan } from '../../auth/context'
import { Button } from '../../components/Button'
import { Card } from '../../components/Card'
import { Icon } from '../../components/Icon'
import { ErrorState, LoadingState } from '../../components/States'
import { Badge } from '../../components/StatusBadge'
import { useToast } from '../../components/toast/context'
import { inputClass } from '../../components/styles'
import { applyServerErrors } from '../../lib/forms'
import { addMonths, capitalize, formatMonth, todayParts, yearMonthKey } from '../../lib/format'

const MONTHS = 12
const schema = z.object({ family_id: z.string().regex(/^\d+$/, 'Choisissez une famille.') })

function RotationRow({ rotation, families, canManage, isCurrent }: { rotation: Rotation; families: Family[]; canManage: boolean; isCurrent: boolean }) {
  const toast = useToast()
  const mutation = useUpdateRotation()
  const [failure, setFailure] = useState<string | null>(null)
  const label = capitalize(formatMonth(rotation.year, rotation.month))
  const selectId = `rotation-${rotation.year}-${rotation.month}`
  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isDirty },
  } = useForm<z.infer<typeof schema>>({
    resolver: zodResolver(schema),
    values: { family_id: rotation.family ? String(rotation.family.id) : '' },
  })
  const error = errors.family_id?.message ?? failure

  return (
    <li className={clsx('flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center', isCurrent && 'bg-church-purple-xl/50')}>
      <div className="flex min-w-44 items-center gap-2">
        {canManage ? (
          <label htmlFor={selectId} className="font-bold text-gray-900">
            {label}
          </label>
        ) : (
          <span className="font-bold text-gray-900">{label}</span>
        )}
        {isCurrent && <Badge tone="purple">En cours</Badge>}
      </div>
      {canManage ? (
        <form
          noValidate
          className="flex flex-1 flex-col gap-1"
          onSubmit={handleSubmit(async ({ family_id }) => {
            setFailure(null)
            try {
              const { data } = await mutation.mutateAsync({ year: rotation.year, month: rotation.month, family_id: Number(family_id) })
              reset({ family_id: data.family ? String(data.family.id) : family_id })
              toast.success(`${label} : famille ${data.family?.name ?? ''} enregistrée.`)
            } catch (err) {
              setFailure(applyServerErrors(err, setError, ['family_id']))
            }
          })}
        >
          <div className="flex gap-2">
            <select
              id={selectId}
              {...register('family_id')}
              aria-invalid={Boolean(error)}
              aria-describedby={error ? `${selectId}-error` : undefined}
              className={clsx(inputClass, 'sm:max-w-64')}
            >
              <option value="">— Aucune famille —</option>
              {families.map((f) => (
                <option key={f.id} value={f.id}>
                  {f.name}
                  {f.active ? '' : ' (inactive)'}
                </option>
              ))}
            </select>
            <Button type="submit" variant="secondary" disabled={!isDirty} pending={mutation.isPending} pendingLabel="…">
              Enregistrer <span className="sr-only">la famille de {label}</span>
            </Button>
          </div>
          {error && (
            <p id={`${selectId}-error`} className="text-sm font-bold text-red-700">
              {error}
            </p>
          )}
        </form>
      ) : (
        <span className="text-gray-900">{rotation.family?.name ?? 'Non définie'}</span>
      )}
    </li>
  )
}

export function RotationsTab() {
  const canManage = useCan('rotations.manage')
  const today = todayParts()
  const [start, setStart] = useState(() => ({ year: today.year, month: today.month }))
  const from = yearMonthKey(start.year, start.month)
  const rotations = useRotations(from, MONTHS)
  const families = useFamilies()
  const end = addMonths(start.year, start.month, MONTHS - 1)

  return (
    <Card
      title="Rotation des familles"
      description={
        canManage
          ? 'Famille de service de chaque mois : elle accueille les visiteurs et reçoit le rapport mensuel.'
          : 'Famille de service de chaque mois (modification réservée aux super administrateurs).'
      }
      className="max-w-3xl"
    >
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <Button variant="secondary" size="sm" onClick={() => setStart((s) => addMonths(s.year, s.month, -MONTHS))}>
          <Icon name="left" className="size-4" />
          12 mois précédents
        </Button>
        <p className="text-sm font-bold text-gray-800" aria-live="polite">
          {capitalize(formatMonth(start.year, start.month))} – {formatMonth(end.year, end.month)}
        </p>
        <Button variant="secondary" size="sm" onClick={() => setStart((s) => addMonths(s.year, s.month, MONTHS))}>
          12 mois suivants
          <Icon name="right" className="size-4" />
        </Button>
      </div>

      {rotations.isPending || families.isPending ? (
        <LoadingState label="Chargement des rotations…" />
      ) : rotations.isError ? (
        <ErrorState error={rotations.error} onRetry={() => rotations.refetch()} />
      ) : families.isError ? (
        <ErrorState error={families.error} onRetry={() => families.refetch()} />
      ) : (
        <ul className="divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200">
          {rotations.data.map((r) => (
            <RotationRow
              key={`${r.year}-${r.month}`}
              rotation={r}
              families={families.data}
              canManage={canManage}
              isCurrent={r.year === today.year && r.month === today.month}
            />
          ))}
        </ul>
      )}
    </Card>
  )
}
