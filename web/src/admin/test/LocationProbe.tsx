import { useLocation } from 'react-router'

/** Expose l'URL courante du routeur mémoire aux assertions des tests. */
export function LocationProbe() {
  const location = useLocation()
  return <div hidden data-testid="location">{`${location.pathname}${location.search}`}</div>
}
