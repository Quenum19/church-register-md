import { useCallback, useEffect } from 'react'
import type { SetURLSearchParams } from 'react-router'

/** Changement de page dans l'URL (`?page=`), la page 1 étant implicite. */
export function usePageNavigation(setParams: SetURLSearchParams) {
  return useCallback(
    (page: number, replace = false) => {
      setParams(
        (prev) => {
          const next = new URLSearchParams(prev)
          if (page > 1) next.set('page', String(page))
          else next.delete('page')
          return next
        },
        { replace },
      )
    },
    [setParams],
  )
}

/** Si la page demandée dépasse la dernière (après un filtrage ou une suppression), on y revient. */
export function useClampPage(requested: number, lastPage: number | null, goToPage: (page: number, replace?: boolean) => void) {
  useEffect(() => {
    if (lastPage !== null && requested > lastPage) goToPage(lastPage, true)
  }, [requested, lastPage, goToPage])
}
