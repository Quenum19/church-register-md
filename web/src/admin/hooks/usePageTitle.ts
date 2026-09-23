import { useEffect } from 'react'

const SUFFIX = 'Administration — Église La Maison de la Destinée'

/** Met à jour le titre du document (annoncé par les lecteurs d'écran lors des navigations). */
export function usePageTitle(title: string) {
  useEffect(() => {
    document.title = title ? `${title} · ${SUFFIX}` : SUFFIX
  }, [title])
}
