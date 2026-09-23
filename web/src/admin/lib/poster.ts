// Affiche QR code dessinée directement sur un <canvas> (pas de capture HTML, pas de script externe).

import type { Verse } from '../../shared/api-types'
import type { QrMatrix } from '../hooks/useQrMatrix'

const WIDTH = 1240 // A4 à 150 dpi
const HEIGHT = 1754
const PURPLE_DK = '#4a0e6b'
const PURPLE = '#6b1f8a'
const GOLD = '#c9a227'
const GOLD_LT = '#e8c547'
const GOLD_DK = '#7a5f0c'
const TEXT = '#374151'

export interface PosterContent {
  churchName: string
  verse: Verse | null
  url: string
  matrix: QrMatrix
}

function wrapText(ctx: CanvasRenderingContext2D, text: string, maxWidth: number): string[] {
  const words = text.split(/\s+/).filter(Boolean)
  const lines: string[] = []
  let line = ''
  for (const word of words) {
    const candidate = line ? `${line} ${word}` : word
    if (ctx.measureText(candidate).width <= maxWidth || !line) {
      line = candidate
    } else {
      lines.push(line)
      line = word
    }
  }
  if (line) lines.push(line)
  return lines
}

function drawLines(ctx: CanvasRenderingContext2D, lines: string[], y: number, lineHeight: number): number {
  lines.forEach((line, i) => ctx.fillText(line, WIDTH / 2, y + i * lineHeight))
  return y + lines.length * lineHeight
}

function goldBand(ctx: CanvasRenderingContext2D, y: number) {
  const gradient = ctx.createLinearGradient(0, 0, WIDTH, 0)
  gradient.addColorStop(0, GOLD_DK)
  gradient.addColorStop(0.5, GOLD_LT)
  gradient.addColorStop(1, GOLD_DK)
  ctx.fillStyle = gradient
  ctx.fillRect(0, y, WIDTH, 24)
}

async function waitForFonts() {
  if (!('fonts' in document)) return
  try {
    await Promise.all([
      document.fonts.load('700 64px "Playfair Display"'),
      document.fonts.load('700 40px "Lato"'),
      document.fonts.load('400 30px "Lato"'),
    ])
  } catch {
    // Polices indisponibles : repli sur les polices système.
  }
}

export async function renderPosterPng(content: PosterContent): Promise<Blob> {
  await waitForFonts()
  const canvas = document.createElement('canvas')
  canvas.width = WIDTH
  canvas.height = HEIGHT
  const ctx = canvas.getContext('2d')
  if (!ctx) throw new Error('Canvas indisponible')

  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, WIDTH, HEIGHT)
  ctx.textAlign = 'center'
  ctx.textBaseline = 'top'

  // En-tête violet
  goldBand(ctx, 0)
  const header = ctx.createLinearGradient(0, 24, WIDTH, 440)
  header.addColorStop(0, PURPLE_DK)
  header.addColorStop(0.5, PURPLE)
  header.addColorStop(1, PURPLE_DK)
  ctx.fillStyle = header
  ctx.fillRect(0, 24, WIDTH, 400)
  ctx.fillStyle = '#ffffff'
  ctx.font = '700 64px "Playfair Display", Georgia, serif'
  const nameLines = wrapText(ctx, content.churchName, WIDTH - 200).slice(0, 3)
  const nameHeight = nameLines.length * 80
  let y = drawLines(ctx, nameLines, 24 + (400 - nameHeight - 60) / 2, 80)
  ctx.fillStyle = GOLD
  ctx.fillRect(WIDTH / 2 - 80, y + 14, 160, 4)
  ctx.fillStyle = '#f3e8ff'
  ctx.font = 'italic 400 32px "Lato", Arial, sans-serif'
  ctx.fillText('Bienvenue parmi nous', WIDTH / 2, y + 34)

  // Consigne
  ctx.fillStyle = PURPLE_DK
  ctx.font = '700 48px "Lato", Arial, sans-serif'
  ctx.fillText('Scannez pour enregistrer votre visite', WIDTH / 2, 490)
  ctx.fillStyle = TEXT
  ctx.font = '400 30px "Lato", Arial, sans-serif'
  ctx.fillText('Ouvrez l’appareil photo de votre téléphone et visez le code.', WIDTH / 2, 556)

  // QR code
  const qrSize = 640
  const qrX = (WIDTH - qrSize) / 2
  const qrY = 630
  ctx.fillStyle = '#f3e8ff'
  ctx.fillRect(qrX - 24, qrY - 24, qrSize + 48, qrSize + 48)
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(qrX - 16, qrY - 16, qrSize + 32, qrSize + 32)
  const quiet = 2
  const cell = qrSize / (content.matrix.size + quiet * 2)
  ctx.fillStyle = '#14041f'
  for (let row = 0; row < content.matrix.size; row++) {
    for (let col = 0; col < content.matrix.size; col++) {
      if (content.matrix.isDark(row, col)) {
        ctx.fillRect(
          Math.floor(qrX + (col + quiet) * cell),
          Math.floor(qrY + (row + quiet) * cell),
          Math.ceil(cell),
          Math.ceil(cell),
        )
      }
    }
  }

  // Verset
  y = qrY + qrSize + 90
  if (content.verse?.text) {
    ctx.fillStyle = TEXT
    ctx.font = 'italic 400 34px "Lato", Arial, sans-serif'
    const verseLines = wrapText(ctx, `« ${content.verse.text} »`, WIDTH - 240).slice(0, 6)
    y = drawLines(ctx, verseLines, y, 48)
    ctx.fillStyle = GOLD_DK
    ctx.font = '700 30px "Lato", Arial, sans-serif'
    ctx.fillText(`— ${content.verse.ref}`, WIDTH / 2, y + 14)
  }

  // Adresse de secours
  ctx.fillStyle = '#4b5563'
  ctx.font = '400 26px "Lato", Arial, sans-serif'
  ctx.fillText(content.url, WIDTH / 2, HEIGHT - 90)
  goldBand(ctx, HEIGHT - 24)

  return new Promise((resolve, reject) => {
    canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('Export PNG impossible'))), 'image/png')
  })
}

/** Téléchargement d'un Blob via un lien temporaire (URL blob: révoquée ensuite). */
export function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.append(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
