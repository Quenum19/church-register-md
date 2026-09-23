import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { Button } from './Button'
import { Field } from './Field'
import { Icon } from './Icon'
import { inputClass } from './styles'

const schema = z.object({
  search: z.string().trim().max(100, 'La recherche ne doit pas dépasser 100 caractères.'),
})

interface SearchFormProps {
  value: string
  onSearch: (value: string) => void
  label?: string
  hint?: string
}

/** Recherche validée à l'envoi ; le champ suit la valeur de l'URL (retour arrière). */
export function SearchForm({ value, onSearch, label = 'Rechercher', hint }: SearchFormProps) {
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema), defaultValues: { search: value } })

  useEffect(() => {
    reset({ search: value })
  }, [value, reset])

  return (
    <form role="search" noValidate onSubmit={handleSubmit(({ search }) => onSearch(search))} className="flex flex-col gap-2 sm:flex-row sm:items-end">
      <Field label={label} hint={hint} error={errors.search?.message} className="flex-1">
        {(control) => <input {...control} {...register('search')} type="search" className={inputClass} />}
      </Field>
      <Button type="submit">
        <Icon name="right" className="size-4" />
        Rechercher
      </Button>
    </form>
  )
}
