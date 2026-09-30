// Fabrique api/tests/Fixtures/qr-vectors.json (extension .cjs : web/package.json declare "type": "module") à partir de la bibliothèque `qrcode` (npm),
// celle qu'utilise le SPA : segment unique en mode octet, correction d'erreur M.
//   node api/tests/Fixtures/qr-vectors.cjs > api/tests/Fixtures/qr-vectors.json
const { create } = require('../../../web/node_modules/qrcode')

const encode = (text) => {
  const qr = create([{ data: text, mode: 'byte' }], { errorCorrectionLevel: 'M' })
  const size = qr.modules.size
  const rows = []
  for (let r = 0; r < size; r++) {
    let line = ''
    for (let c = 0; c < size; c++) line += qr.modules.get(r, c) ? '1' : '0'
    rows.push(line)
  }
  return { text, version: qr.version, size, mask: qr.maskPattern, rows }
}

// Matrices complètes : versions représentatives (v7 ajoute le bloc d'information de version,
// v10 passe l'indicateur de longueur à 16 bits, v20 est la limite de l'implémentation PHP).
const matrices = [
  'A',
  'https://registre.newinechurch.org/e/culte-4-octobre',
  "Église La Maison de la Destinée — culte spécial",
  'a'.repeat(84),
  'a'.repeat(122),
  'a'.repeat(213),
  'b'.repeat(666),
].map(encode)

// Capacités : dernière longueur tenant dans chaque version (vérifie la table de correction).
const capacities = [14, 26, 42, 62, 84, 106, 122, 152, 180, 213, 251, 287, 331, 362, 412, 450, 504, 560, 624, 666]
const versions = []
capacities.forEach((max, i) => {
  versions.push({ length: max, version: i + 1 })
  if (i + 1 < capacities.length) versions.push({ length: max + 1, version: i + 2 })
})

process.stdout.write(JSON.stringify({ matrices, versions }, null, 1) + '\n')
