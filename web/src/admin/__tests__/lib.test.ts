import { describe, expect, it } from 'vitest'
import { ApiError } from '../../shared/http'
import { describeAnswers } from '../lib/answers'
import { errorMessage } from '../lib/errors'
import { addMonths, formatDate, formatDateTime, formatMonth } from '../lib/format'
import { loginPathFor, safeAdminPath } from '../lib/paths'
import { formatPhone, splitE164 } from '../lib/phone'
import { passwordPolicy } from '../lib/schemas'
import { exportUrl, parseVisitorFilters, toApiQuery, withFilters } from '../lib/visitorFilters'

describe('dates en français (Africa/Abidjan)', () => {
  it('formate dates et horodatages', () => {
    expect(formatDate('2026-09-22')).toBe('22 septembre 2026')
    expect(formatDateTime('2026-09-22T23:30:00+00:00')).toBe('22 septembre 2026 à 23:30')
    expect(formatDate(null)).toBe('—')
    expect(formatMonth(2026, 2)).toBe('février 2026')
  })

  it('décale les mois en changeant d’année', () => {
    expect(addMonths(2026, 11, 3)).toEqual({ year: 2027, month: 2 })
    expect(addMonths(2026, 1, -1)).toEqual({ year: 2025, month: 12 })
  })
})

describe('redirections internes', () => {
  it('n’accepte que des chemins du dashboard', () => {
    expect(safeAdminPath('/admin/visiteurs?status=recurrent')).toBe('/admin/visiteurs?status=recurrent')
    expect(safeAdminPath('/admin')).toBe('/admin')
    expect(safeAdminPath('//evil.example/admin')).toBeNull()
    expect(safeAdminPath('https://evil.example')).toBeNull()
    expect(safeAdminPath('/administrateur-pirate')).toBeNull()
    expect(safeAdminPath('/admin/connexion')).toBeNull()
    expect(loginPathFor('/admin')).toBe('/admin/connexion')
  })
})

describe('filtres des visiteurs', () => {
  it('ignore les valeurs invalides de l’URL', () => {
    const filters = parseVisitorFilters(new URLSearchParams('status=pirate&family_id=abc&from=2026-13&sort=x&page=0&search=%20%20'))
    expect(filters).toEqual({})
  })

  it('revient à la page 1 à chaque changement de filtre', () => {
    const next = withFilters(new URLSearchParams('page=4&status=recurrent'), { family_id: '2', status: undefined })
    expect(next.toString()).toBe('family_id=2')
  })

  it('construit la requête API et le lien d’export', () => {
    const filters = parseVisitorFilters(new URLSearchParams('search=a b&status=non_membre&page=3'))
    expect(toApiQuery(filters)).toMatchObject({ search: 'a b', status: 'non_membre', sort: '-created_at', page: 3 })
    expect(exportUrl('csv', filters)).toBe('/api/admin/exports/visitors.csv?search=a+b&status=non_membre')
  })
})

describe('téléphones et réponses', () => {
  it('décompose et affiche un numéro E.164', () => {
    expect(splitE164('+2250700000001')).toEqual({ country: 'CI', number: '0700000001' })
    expect(splitE164('+447700900000')).toEqual({ country: 'OTHER', number: '+447700900000' })
    expect(formatPhone('+2250700000001')).toBe('+225 07 00 00 00 01')
  })

  it('traduit les codes de réponse', () => {
    expect(describeAnswers({ visit_reason: 'autres', visit_reason_other: 'Mariage' })).toEqual([
      { label: 'Motivation', value: 'Autre raison' },
      { label: 'Précision', value: 'Mariage' },
    ])
  })
})

describe('messages d’erreur', () => {
  it('explique les codes métier du contrat, même quand le serveur renvoie un message générique (5xx)', () => {
    // Un 5xx arrive toujours avec le message générique (voir shared/http.ts) : seul le code renseigne.
    const mailFailed = new ApiError(503, 'mail_failed', 'Une erreur est survenue. Merci de réessayer.')
    expect(errorMessage(mailFailed)).toMatch(/^L'envoi de l'e-mail a échoué/)
    expect(errorMessage(new ApiError(409, 'send_in_progress', 'Conflit.'))).toMatch(/déjà en cours/)
    expect(errorMessage(new ApiError(422, 'too_many_rows', 'Trop.'))).toMatch(/^Plus de 1\s000 lignes/)
    expect(errorMessage(new ApiError(409, 'invitation_not_pending', 'Conflit.'))).toMatch(/déjà défini son mot de passe/)
    expect(errorMessage(new ApiError(409, 'not_member', 'Conflit.'))).toMatch(/n'est pas membre/)
    expect(errorMessage(new ApiError(400, 'bad_request', 'Requête invalide.'))).toMatch(/Rechargez la page/)
  })

  it('a un message pour chaque code métier signalé par le backend', () => {
    const codes = [
      'mail_failed',
      'send_in_progress',
      'invitation_not_pending',
      'two_factor_already_enabled',
      'two_factor_expired',
      'not_member',
      'too_many_rows',
      'already_sent',
      'no_recipients',
      'not_eligible',
      'already_member',
      'forbidden_self_change',
      'last_super_admin',
      'bad_request',
    ] as const
    for (const code of codes) {
      expect(errorMessage(new ApiError(409, code, `serveur:${code}`)), code).not.toBe(`serveur:${code}`)
    }
  })

  it('garde le message du serveur pour un code inconnu', () => {
    expect(errorMessage(new ApiError(409, 'autre_conflit', 'Message précis du serveur.'))).toBe('Message précis du serveur.')
  })
})

describe('politique de mot de passe', () => {
  it('borne la longueur entre 12 et 255 caractères', () => {
    expect(passwordPolicy.safeParse('abcdefghij12').success).toBe(true)
    expect(passwordPolicy.safeParse('abcdefghi12').success).toBe(false)
    expect(passwordPolicy.safeParse(`${'a'.repeat(254)}1`).success).toBe(true)
    const tooLong = passwordPolicy.safeParse(`${'a'.repeat(255)}1`)
    expect(tooLong.success).toBe(false)
    expect(tooLong.error?.issues[0]?.message).toBe('Le mot de passe ne doit pas dépasser 255 caractères.')
  })
})
