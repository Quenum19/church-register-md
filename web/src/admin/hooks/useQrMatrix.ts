import { useEffect, useState } from 'react'

/** Matrice d'un QR code (modules noirs/blancs), générée localement : aucune ressource externe. */
export interface QrMatrix {
  size: number
  isDark: (row: number, col: number) => boolean
}

type QrModule = typeof import('qrcode')

async function loadQrCode(): Promise<QrModule['create']> {
  // Bibliothèque chargée à la demande (chunk séparé) : seules les pages QR code et 2FA en ont besoin.
  const mod = (await import('qrcode')) as QrModule & { default?: QrModule }
  const create = mod.create ?? mod.default?.create
  if (!create) throw new Error('qrcode indisponible')
  return create
}

export async function createQrMatrix(text: string): Promise<QrMatrix> {
  const create = await loadQrCode()
  const { modules } = create(text, { errorCorrectionLevel: 'M' })
  return { size: modules.size, isDark: (row, col) => modules.get(row, col) === 1 }
}

interface QrState {
  text: string
  matrix: QrMatrix | null
  failed: boolean
}

export function useQrMatrix(text: string | null | undefined) {
  const [state, setState] = useState<QrState | null>(null)

  useEffect(() => {
    if (!text) return
    let cancelled = false
    createQrMatrix(text)
      .then((matrix) => {
        if (!cancelled) setState({ text, matrix, failed: false })
      })
      .catch(() => {
        if (!cancelled) setState({ text, matrix: null, failed: true })
      })
    return () => {
      cancelled = true
    }
  }, [text])

  const current = text && state?.text === text ? state : null
  return { matrix: current?.matrix ?? null, failed: current?.failed ?? false, loading: Boolean(text) && current === null }
}
