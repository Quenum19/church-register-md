import { useEffect, useState } from 'react'
import type { FamilyRef } from '../../shared/api-types'
import { loadPublicConfig } from '../journey/api'

/**
 * Familles proposées pour « Sa famille dans l'Église ». Liste facultative :
 * si la configuration publique n'est pas joignable, le champ est simplement masqué.
 */
export function useFamilies(): FamilyRef[] {
  const [families, setFamilies] = useState<FamilyRef[]>([])
  useEffect(() => {
    let active = true
    loadPublicConfig()
      .then((config) => {
        if (active && Array.isArray(config.families)) setFamilies(config.families)
      })
      .catch(() => {
        // Liste facultative : sans elle, le champ « famille de l'invitant » est simplement masqué.
      })
    return () => {
      active = false
    }
  }, [])
  return families
}
