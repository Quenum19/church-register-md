#!/usr/bin/env node
// Budget de taille du chargement initial du SPA (sans dépendance).
//
// Lit web/dist/index.html, repère les ressources chargées au démarrage :
//   - <script type="module" src="…">
//   - <link rel="modulepreload" href="…">
//   - <link rel="stylesheet" href="…">
// puis additionne leur taille gzip (zlib, niveau par défaut, comme le rapport de Vite).
// Les chunks chargés à la demande (import() dynamiques, ex. le dashboard /admin) ne sont
// PAS comptés : ils n'apparaissent pas dans index.html.
//
// Usage :
//   node scripts/check-bundle-size.mjs [seuil_ko] [--dist <dossier>]
//   node scripts/check-bundle-size.mjs 100 --dist web/dist
//
// Codes de sortie : 0 = budget respecté, 1 = budget dépassé, 2 = erreur (fichier absent…).

import { existsSync, readFileSync, appendFileSync } from 'node:fs'
import { dirname, join, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import { gzipSync } from 'node:zlib'

const DEFAULT_BUDGET_KB = 100
const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')

function fail(message) {
  console.error(`Erreur : ${message}`)
  process.exit(2)
}

function parseArgs(argv) {
  let budgetKb = DEFAULT_BUDGET_KB
  let distDir = join(repoRoot, 'web', 'dist')
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i]
    if (arg === '--help' || arg === '-h') {
      console.log('Usage : node scripts/check-bundle-size.mjs [seuil_ko] [--dist <dossier>]')
      process.exit(0)
    } else if (arg === '--dist') {
      const value = argv[++i]
      if (!value) fail('--dist attend un chemin.')
      distDir = resolve(value)
    } else if (arg.startsWith('--dist=')) {
      distDir = resolve(arg.slice('--dist='.length))
    } else if (/^\d+(\.\d+)?$/.test(arg)) {
      budgetKb = Number(arg)
    } else {
      fail(`argument inconnu « ${arg} ».`)
    }
  }
  return { budgetKb, distDir }
}

// Attributs d'une balise HTML ouvrante (valeurs entre guillemets simples/doubles ou nues).
function parseAttributes(tag) {
  const attrs = {}
  const re = /([^\s"'<>/=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g
  const body = tag.replace(/^<\s*\w+/, '').replace(/\/?>$/, '')
  let match
  while ((match = re.exec(body)) !== null) {
    attrs[match[1].toLowerCase()] = match[2] ?? match[3] ?? match[4] ?? ''
  }
  return attrs
}

function initialAssets(html) {
  const withoutComments = html.replace(/<!--[\s\S]*?-->/g, '')
  const assets = []
  for (const [tag] of withoutComments.matchAll(/<script\b[^>]*>/gi)) {
    const a = parseAttributes(tag)
    if (a.src && (a.type ?? '').toLowerCase() === 'module') assets.push({ kind: 'script', url: a.src })
  }
  for (const [tag] of withoutComments.matchAll(/<link\b[^>]*>/gi)) {
    const a = parseAttributes(tag)
    const rels = (a.rel ?? '').toLowerCase().split(/\s+/)
    if (!a.href) continue
    if (rels.includes('modulepreload')) assets.push({ kind: 'modulepreload', url: a.href })
    else if (rels.includes('stylesheet')) assets.push({ kind: 'stylesheet', url: a.href })
  }
  return assets
}

function toLocalPath(distDir, url) {
  if (/^(?:[a-z][a-z0-9+.-]*:)?\/\//i.test(url) || url.startsWith('data:')) return null // ressource externe
  const clean = decodeURIComponent(url.split(/[?#]/)[0]).replace(/^\.?\//, '')
  const file = resolve(distDir, clean)
  if (file !== distDir && !file.startsWith(distDir + sep)) fail(`chemin hors de dist : ${url}`)
  return file
}

const kb = (bytes) => (bytes / 1024).toFixed(1)

const { budgetKb, distDir } = parseArgs(process.argv.slice(2))
const indexFile = join(distDir, 'index.html')
if (!existsSync(indexFile)) fail(`${indexFile} introuvable. Lancez d'abord « npm run build » dans web/.`)

const html = readFileSync(indexFile, 'utf8')
const seen = new Set()
const rows = []
const external = []
for (const asset of initialAssets(html)) {
  const file = toLocalPath(distDir, asset.url)
  if (file === null) {
    external.push(asset.url)
    continue
  }
  if (seen.has(file)) continue
  seen.add(file)
  if (!existsSync(file)) fail(`${asset.url} est référencé par index.html mais absent de ${distDir}.`)
  const content = readFileSync(file)
  rows.push({ ...asset, raw: content.length, gzip: gzipSync(content).length })
}

if (rows.length === 0) fail('aucun script module ni feuille de style trouvé dans index.html.')

const total = rows.reduce((sum, r) => sum + r.gzip, 0)
const totalRaw = rows.reduce((sum, r) => sum + r.raw, 0)
const budgetBytes = budgetKb * 1024
const ok = total <= budgetBytes

const width = Math.max(...rows.map((r) => r.url.length), 'Total'.length)
console.log(`Chargement initial du SPA (${indexFile})\n`)
console.log(`${'Ressource'.padEnd(width)}  ${'Type'.padEnd(13)}  ${'Brut'.padStart(9)}  ${'Gzip'.padStart(9)}`)
for (const r of rows) {
  console.log(`${r.url.padEnd(width)}  ${r.kind.padEnd(13)}  ${(kb(r.raw) + ' Ko').padStart(9)}  ${(kb(r.gzip) + ' Ko').padStart(9)}`)
}
console.log(`${'Total'.padEnd(width)}  ${''.padEnd(13)}  ${(kb(totalRaw) + ' Ko').padStart(9)}  ${(kb(total) + ' Ko').padStart(9)}`)
if (external.length > 0) {
  console.warn(`\nAttention : ressources externes non comptées (interdites par la CSP) : ${external.join(', ')}`)
}
const verdict = ok
  ? `OK : ${kb(total)} Ko gzip ≤ budget de ${budgetKb} Ko.`
  : `ÉCHEC : ${kb(total)} Ko gzip > budget de ${budgetKb} Ko (dépassement de ${kb(total - budgetBytes)} Ko).`
console.log(`\n${verdict}`)

// Résumé dans l'interface GitHub Actions.
if (process.env.GITHUB_STEP_SUMMARY) {
  const lines = [
    '### Budget du chargement initial (gzip)',
    '',
    '| Ressource | Type | Brut | Gzip |',
    '|---|---|---:|---:|',
    ...rows.map((r) => `| \`${r.url}\` | ${r.kind} | ${kb(r.raw)} Ko | ${kb(r.gzip)} Ko |`),
    `| **Total** | | ${kb(totalRaw)} Ko | **${kb(total)} Ko** |`,
    '',
    `${ok ? '✅' : '❌'} ${verdict}`,
    '',
  ]
  try {
    appendFileSync(process.env.GITHUB_STEP_SUMMARY, lines.join('\n'))
  } catch {
    // Le résumé est facultatif.
  }
}

process.exit(ok ? 0 : 1)
