import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import type { VisitorFilters } from '../../../shared/api-types'
import { useFamilies } from '../../api/settings'
import { Button } from '../../components/Button'
import { Field } from '../../components/Field'
import { Icon } from '../../components/Icon'
import { inputClass } from '../../components/styles'
import { DEFAULT_SORT, hasActiveFilters, SORT_OPTIONS, STATUS_FILTER_OPTIONS } from '../../lib/visitorFilters'

const searchSchema = z.object({
  search: z.string().trim().max(100, 'La recherche ne doit pas dépasser 100 caractères.'),
})

export type FilterChange = Partial<Record<'search' | 'status' | 'family_id' | 'from' | 'to' | 'sort', string | undefined>>

interface VisitorFiltersBarProps {
  filters: VisitorFilters
  onChange: (changes: FilterChange, options?: { push?: boolean }) => void
  onReset: () => void
  invalidPeriod: boolean
}

/** Filtres combinables (ET). La recherche est validée à l'envoi ; les listes et dates s'appliquent au changement. */
export function VisitorFiltersBar({ filters, onChange, onReset, invalidPeriod }: VisitorFiltersBarProps) {
  const families = useFamilies()
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<z.infer<typeof searchSchema>>({
    resolver: zodResolver(searchSchema),
    defaultValues: { search: filters.search ?? '' },
  })

  // Retour arrière / lien direct : le champ suit l'URL.
  useEffect(() => {
    reset({ search: filters.search ?? '' })
  }, [filters.search, reset])

  return (
    <section aria-label="Filtres" className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
      <form
        role="search"
        onSubmit={handleSubmit(({ search }) => onChange({ search: search || undefined }, { push: true }))}
        className="flex flex-col gap-2 sm:flex-row sm:items-end"
        noValidate
      >
        <Field label="Rechercher" hint="Nom, commune, quartier ou téléphone" error={errors.search?.message} className="flex-1">
          {(control) => <input {...control} {...register('search')} type="search" className={inputClass} />}
        </Field>
        <Button type="submit" className="sm:mb-0">
          <Icon name="right" className="size-4" />
          Rechercher
        </Button>
      </form>

      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <Field label="Statut">
          {(control) => (
            <select
              {...control}
              value={filters.status ?? ''}
              onChange={(e) => onChange({ status: e.target.value || undefined })}
              className={inputClass}
            >
              <option value="">Tous les statuts</option>
              {STATUS_FILTER_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
          )}
        </Field>
        <Field label="Famille d’accueil">
          {(control) => (
            <select
              {...control}
              value={filters.family_id ? String(filters.family_id) : ''}
              onChange={(e) => onChange({ family_id: e.target.value || undefined })}
              className={inputClass}
            >
              <option value="">Toutes les familles</option>
              {families.data?.map((f) => (
                <option key={f.id} value={f.id}>
                  {f.name}
                </option>
              ))}
              {/* Famille présente dans l'URL mais pas encore chargée : on garde la valeur sélectionnée. */}
              {filters.family_id && !families.data?.some((f) => f.id === filters.family_id) && (
                <option value={filters.family_id}>Famille n° {filters.family_id}</option>
              )}
            </select>
          )}
        </Field>
        <Field label="1re visite du" error={invalidPeriod ? 'La date de début doit précéder la date de fin.' : undefined}>
          {(control) => (
            <input
              {...control}
              type="date"
              value={filters.from ?? ''}
              max={filters.to}
              onChange={(e) => onChange({ from: e.target.value || undefined })}
              className={inputClass}
            />
          )}
        </Field>
        <Field label="au">
          {(control) => (
            <input
              {...control}
              type="date"
              value={filters.to ?? ''}
              min={filters.from}
              onChange={(e) => onChange({ to: e.target.value || undefined })}
              className={inputClass}
            />
          )}
        </Field>
        <Field label="Trier par">
          {(control) => (
            <select
              {...control}
              value={filters.sort ?? DEFAULT_SORT}
              onChange={(e) => onChange({ sort: e.target.value })}
              className={inputClass}
            >
              {SORT_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
          )}
        </Field>
      </div>

      {(hasActiveFilters(filters) || filters.sort) && (
        <div className="mt-4">
          <Button variant="ghost" size="sm" onClick={onReset}>
            <Icon name="close" className="size-4" />
            Réinitialiser les filtres
          </Button>
        </div>
      )}
    </section>
  )
}
