// Types du cœur d'encodage de « qrcode » (import direct, sans les moteurs de rendu).
declare module 'qrcode/lib/core/qrcode' {
  export interface QrBitMatrix {
    size: number
    get(row: number, col: number): number | boolean
  }
  export function create(
    text: string,
    options?: { errorCorrectionLevel?: 'L' | 'M' | 'Q' | 'H' },
  ): { modules: QrBitMatrix }
}
