// Champs communs aux deux variantes du formulaire de première visite : identité,
// origine (« Comment avez-vous connu l'Église ? ») et ses précisions conditionnelles.
// Seule la liste des origines change : sept en temps ordinaire, deux sur un lien événement.
// Hors du bundle initial.

import { Controller, type FieldValues, type Path, type UseFormReturn } from 'react-hook-form'
import type { FamilyRef } from '../../shared/api-types'
import { SOURCE_LABELS, type Source } from '../../shared/domain'
import { SelectField, TextField } from '../ui/Field'
import { ChoiceGroup, ChoiceOption, TextArea } from './FormFields'

/** Clés partagées par `Visit1Values` et `Visit1EventValues`. */
export interface Visit1IdentityValues {
  full_name: string
  commune: string
  quartier: string
  source: string
  source_other: string
  invited_by: string
  inviter_congregation_id: string
  inviter_family_id: string
}

interface Visit1IdentityFieldsProps<T extends FieldValues & Visit1IdentityValues> {
  form: UseFormReturn<T>
  errorOf: (field: Path<T>) => string | undefined
  /** Origines proposées, dans l'ordre d'affichage. */
  sources: readonly Source[]
  families: FamilyRef[]
  /** Congrégations proposées (distinctes des familles de service). */
  congregations: FamilyRef[]
}

export function Visit1IdentityFields<T extends FieldValues & Visit1IdentityValues>({
  form,
  errorOf,
  sources,
  families,
  congregations,
}: Visit1IdentityFieldsProps<T>) {
  const { register, control, watch } = form
  // `T` contient ces clés par construction, mais TypeScript ne peut pas le déduire de `Path<T>`.
  const path = <K extends keyof Visit1IdentityValues>(key: K) => key as unknown as Path<T>
  const source = watch(path('source')) as string
  const sourceError = errorOf(path('source'))

  return (
    <>
      <TextField
        id="full_name"
        label="Nom et prénoms"
        autoComplete="name"
        autoCapitalize="words"
        maxLength={100}
        error={errorOf(path('full_name'))}
        {...register(path('full_name'))}
      />
      <TextField
        id="commune"
        label="Commune de résidence"
        hint="Par exemple : Cocody, Yopougon…"
        autoComplete="address-level2"
        maxLength={80}
        error={errorOf(path('commune'))}
        {...register(path('commune'))}
      />
      <TextField
        id="quartier"
        label="Quartier"
        hint="Par exemple : Angré, Riviera 2…"
        autoComplete="address-level3"
        maxLength={80}
        error={errorOf(path('quartier'))}
        {...register(path('quartier'))}
      />

      <ChoiceGroup id="source" legend="Comment avez-vous connu l'Église ?" error={sourceError}>
        {sources.map((value) => (
          <ChoiceOption
            key={value}
            id={`source-${value}`}
            type="radio"
            value={value}
            label={SOURCE_LABELS[value]}
            invalid={Boolean(sourceError)}
            aria-describedby={sourceError ? 'source-error' : undefined}
            {...register(path('source'))}
          />
        ))}
      </ChoiceGroup>

      {source === 'invite_membre' && (
        <div className="flex flex-col gap-4 border-l-4 border-church-gold pl-4">
          <TextField
            id="invited_by"
            label="Nom de la personne qui vous a invité(e)"
            autoComplete="off"
            maxLength={100}
            error={errorOf(path('invited_by'))}
            {...register(path('invited_by'))}
          />
          {/* L'Église compte plusieurs congrégations : savoir d'où vient l'invitant oriente
              le suivi. Facultatif — personne ne doit buter dessus. */}
          {congregations.length > 0 && (
            <Controller
              control={control}
              name={path('inviter_congregation_id')}
              render={({ field }) => (
                <SelectField
                  id="inviter_congregation_id"
                  label="Sa congrégation"
                  optional
                  error={errorOf(path('inviter_congregation_id'))}
                  name={field.name}
                  ref={field.ref}
                  value={String(field.value ?? '')}
                  onChange={field.onChange}
                  onBlur={field.onBlur}
                >
                  <option value="">Je ne sais pas</option>
                  {congregations.map((congregation) => (
                    <option key={congregation.id} value={String(congregation.id)}>
                      {congregation.name}
                    </option>
                  ))}
                </SelectField>
              )}
            />
          )}
          {families.length > 0 && (
            <Controller
              control={control}
              name={path('inviter_family_id')}
              render={({ field }) => (
                <SelectField
                  id="inviter_family_id"
                  label="Sa famille dans l'Église"
                  optional
                  error={errorOf(path('inviter_family_id'))}
                  name={field.name}
                  ref={field.ref}
                  value={String(field.value ?? '')}
                  onChange={field.onChange}
                  onBlur={field.onBlur}
                >
                  <option value="">Je ne sais pas</option>
                  {families.map((family) => (
                    <option key={family.id} value={String(family.id)}>
                      Famille {family.name}
                    </option>
                  ))}
                </SelectField>
              )}
            />
          )}
        </div>
      )}

      {source === 'autre' && (
        <div className="border-l-4 border-church-gold pl-4">
          <TextArea
            id="source_other"
            label="Précisez comment vous avez connu l'Église"
            maxLength={200}
            rows={2}
            error={errorOf(path('source_other'))}
            {...register(path('source_other'))}
          />
        </div>
      )}
    </>
  )
}
