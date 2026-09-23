// Génération locale du QR code (aucun service externe) : on n'importe que le cœur
// d'encodage de la bibliothèque « qrcode », sans ses moteurs de rendu canvas/PNG.

import { create } from 'qrcode/lib/core/qrcode'

export interface QrSvg {
  /** Côté du carré en modules, zone de silence comprise. */
  size: number
  /** Tracé SVG des modules sombres (une ligne par suite de modules contigus). */
  path: string
}

/** Zone de silence recommandée par la norme : 4 modules. */
const QUIET_ZONE = 4

export function buildQrSvg(text: string): QrSvg {
  const { modules } = create(text, { errorCorrectionLevel: 'M' })
  const n = modules.size
  let path = ''
  for (let y = 0; y < n; y++) {
    let x = 0
    while (x < n) {
      if (!modules.get(y, x)) {
        x++
        continue
      }
      const start = x
      while (x < n && modules.get(y, x)) x++
      path += `M${start + QUIET_ZONE} ${y + QUIET_ZONE}h${x - start}v1h-${x - start}z`
    }
  }
  return { size: n + QUIET_ZONE * 2, path }
}
