// Classes Tailwind partagées du parcours visiteur.
// Contrastes vérifiés : texte ≥ 4,5:1, bordures de champs ≥ 3:1 (gray-500 sur blanc ≈ 4,8:1),
// or uniquement en church-gold-dk sur fond clair, cibles tactiles ≥ 44 px.

export const focusRing =
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-church-purple'

export const buttonPrimary =
  'inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-linear-to-r from-church-gold to-church-gold-lt px-6 py-3 ' +
  'font-display text-lg font-bold text-church-purple-dk shadow-md shadow-church-gold/40 transition hover:shadow-lg hover:brightness-105 ' +
  `disabled:cursor-not-allowed disabled:opacity-60 disabled:shadow-none ${focusRing}`

export const buttonSecondary =
  'inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl border-2 border-church-purple bg-white px-5 py-3 ' +
  'font-bold text-church-purple transition hover:bg-church-purple-xl disabled:cursor-not-allowed disabled:opacity-60 ' +
  focusRing

export const inputBase =
  'block min-h-12 w-full rounded-xl border border-gray-500 bg-white px-4 py-3 text-base text-gray-900 placeholder:text-gray-500 ' +
  'focus:border-church-purple focus:outline-none focus:ring-2 focus:ring-church-purple ' +
  'aria-invalid:border-red-700 aria-invalid:ring-1 aria-invalid:ring-red-700'

export const labelBase = 'font-bold text-church-purple-dk'

export const hintBase = 'text-sm text-gray-700'

export const textLinkOnDark =
  'rounded font-bold text-white underline underline-offset-2 hover:text-church-gold-lt ' +
  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white'
