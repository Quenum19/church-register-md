import { useEffect, useState } from 'react'
import type { FamilyRef } from '../../shared/api-types'
import { loadPublicConfig } from '../journey/api'

/**
 * Congrégations proposées pour « Sa congrégation ». Liste facultative :
 * si la configuration publique n'est pas joignable, le champ est simplement masqué.
 *
 * À ne pas confondre avec useFamilies() : la famille organise le service d'accueil,
 * la congrégation dit à quelle assemblée la personne appartient.
 */
export function useCongregations(): FamilyRef[] {
  const [congregations, setCongregations] = useState<FamilyRef[]>([])
  useEffect(() => {
    let active = true
    loadPublicConfig()
      .then((config) => {
        if (active && Array.isArray(config.congregations)) setCongregations(config.congregations)
      })
      .catch(() => {
        // Liste facultative : sans elle, le champ « congrégation de l'invitant » est masqué.
      })
    return () => {
      active = false
    }
  }, [])
  return congregations
}
