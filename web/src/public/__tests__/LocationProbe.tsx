import { useLocation } from 'react-router'

/** Expose le chemin courant du routeur en mémoire aux assertions des tests. */
export function LocationProbe() {
  const location = useLocation()
  return <output data-testid="location">{location.pathname}</output>
}
