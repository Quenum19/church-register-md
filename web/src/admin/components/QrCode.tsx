import { clsx } from 'clsx'
import { useMemo } from 'react'
import type { QrMatrix } from '../hooks/useQrMatrix'

const QR_QUIET_ZONE = 2

/** QR code vectoriel (SVG) : net à l'écran comme à l'impression. */
export function QrCode({ matrix, label, className }: { matrix: QrMatrix; label: string; className?: string }) {
  const total = matrix.size + QR_QUIET_ZONE * 2
  const path = useMemo(() => {
    const parts: string[] = []
    for (let row = 0; row < matrix.size; row++) {
      for (let col = 0; col < matrix.size; col++) {
        if (matrix.isDark(row, col)) parts.push(`M${col + QR_QUIET_ZONE} ${row + QR_QUIET_ZONE}h1v1h-1z`)
      }
    }
    return parts.join('')
  }, [matrix])

  return (
    <svg
      role="img"
      aria-label={label}
      viewBox={`0 0 ${total} ${total}`}
      shapeRendering="crispEdges"
      className={clsx('block h-auto w-full', className)}
    >
      <rect width={total} height={total} fill="#ffffff" />
      <path d={path} fill="#14041f" />
    </svg>
  )
}
