import { describe, expect, it } from 'vitest'
import { cleanPhoneInput, formatPhoneWithDial, phoneForApi, validatePhone } from '../journey/phone'
import {
  createJourneyStore,
  emptyState,
  parseState,
  STATE_MAX_AGE_MS,
  STORAGE_KEY,
  type JourneyState,
} from '../journey/store'
import { tooManyRequestsMessage } from '../journey/errors'
import { buildQrSvg } from '../qrcode/qr'

describe('Saisie des numéros', () => {
  it('plafonne les chiffres et garde les espaces', () => {
    expect(cleanPhoneInput('07 00 00 00 00', 'CI')).toBe('07 00 00 00 00')
    expect(cleanPhoneInput('07000000001234', 'CI')).toBe('0700000000')
    expect(cleanPhoneInput('07  00-00.00/00', 'CI')).toBe('07 00 00 00 00')
    expect(cleanPhoneInput('abc07', 'CI')).toBe('07')
  })

  it('retire un indicatif collé du pays choisi', () => {
    expect(cleanPhoneInput('+225 07 00 00 00 00', 'CI')).toBe('07 00 00 00 00')
    expect(cleanPhoneInput('00225 0700000000', 'CI')).toBe('0700000000')
    expect(cleanPhoneInput('+33 6 12 34 56 78', 'FR')).toBe('6 12 34 56 78')
  })

  it('n’accepte « + » qu’en tête et pour « Autre pays »', () => {
    expect(cleanPhoneInput('+44 7700 900000', 'OTHER')).toBe('+44 7700 900000')
    expect(cleanPhoneInput('44+7700', 'OTHER')).toBe('447700')
    expect(cleanPhoneInput('+44 7700', 'SN')).toBe('44 7700')
  })

  it('valide selon le pays', () => {
    expect(validatePhone('07 00 00 00 00', 'CI')).toBeNull()
    expect(validatePhone('0700000000', 'CI')).toBeNull()
    expect(validatePhone('', 'CI')).toBe('Saisissez votre numéro de téléphone.')
    expect(validatePhone('070000000', 'CI')).toBe('Le numéro doit comporter 10 chiffres (9 saisis).')
    expect(validatePhone('024 000 000', 'GH')).toBeNull()
    expect(validatePhone('7700900000', 'OTHER')).toMatch(/commençant par « \+ »/)
    expect(validatePhone('06', 'FR', 'whatsapp')).toBe('Le numéro WhatsApp doit comporter entre 9 et 10 chiffres (2 saisis).')
  })

  it('formate pour l’API et l’affichage', () => {
    expect(phoneForApi('07 00 00 00 00', 'CI')).toBe('0700000000')
    expect(phoneForApi('+44 7700 900000', 'OTHER')).toBe('+447700900000')
    expect(formatPhoneWithDial('0700000000', 'CI')).toBe('+225 07 00 00 00 00')
    expect(formatPhoneWithDial('+44  7700 900000', 'OTHER')).toBe('+44 7700 900000')
  })
})

describe('État du parcours', () => {
  const valid = (patch: Partial<JourneyState> = {}) =>
    JSON.stringify({
      ...emptyState(),
      identified: { country: 'CI', phone: '0700000000' },
      step: 2,
      token: 't',
      ...patch,
    })

  it('relit un état valide', () => {
    expect(parseState(valid())).toMatchObject({ step: 2, token: 't', identified: { country: 'CI' } })
  })

  it('écarte version inconnue, JSON invalide, état périmé et valeurs douteuses', () => {
    expect(parseState(valid({ version: 99 } as unknown as Partial<JourneyState>)).step).toBeNull()
    expect(parseState('{oops').step).toBeNull()
    expect(parseState(valid({ updatedAt: Date.now() - STATE_MAX_AGE_MS - 1000 })).step).toBeNull()
    expect(parseState(valid({ step: 7 } as unknown as Partial<JourneyState>)).step).toBeNull()
    expect(
      parseState(valid({ identified: { country: 'XX', phone: '1' } } as unknown as Partial<JourneyState>)).identified,
    ).toBeNull()
  })

  it('ne conserve les données personnelles qu’une heure', () => {
    expect(STATE_MAX_AGE_MS).toBe(60 * 60 * 1000)
  })

  it('ignore ET efface du stockage un état plus vieux que la limite', () => {
    // Tablette d'accueil partagée : le parcours abandonné du visiteur précédent.
    sessionStorage.setItem(
      STORAGE_KEY,
      valid({
        updatedAt: Date.now() - STATE_MAX_AGE_MS - 1000,
        drafts: { 1: { full_name: 'Awa Koné', commune: 'Cocody', quartier: 'Riviera' } },
      } as unknown as Partial<JourneyState>),
    )

    const store = createJourneyStore(sessionStorage)

    expect(store.getState()).toMatchObject({ identified: null, step: null, token: null, drafts: {} })
    // Et surtout : plus rien à lire dans le stockage du navigateur.
    expect(sessionStorage.getItem(STORAGE_KEY)).toBeNull()
  })

  it('garde un état encore dans la limite', () => {
    sessionStorage.setItem(STORAGE_KEY, valid({ updatedAt: Date.now() - STATE_MAX_AGE_MS + 60_000 }))
    const store = createJourneyStore(sessionStorage)
    expect(store.getState()).toMatchObject({ step: 2, identified: { phone: '0700000000' } })
    expect(sessionStorage.getItem(STORAGE_KEY)).not.toBeNull()
  })

  it('efface aussi un état illisible ou d’une autre version', () => {
    sessionStorage.setItem(STORAGE_KEY, '{pas du json')
    expect(createJourneyStore(sessionStorage).getState().step).toBeNull()
    expect(sessionStorage.getItem(STORAGE_KEY)).toBeNull()

    sessionStorage.setItem(STORAGE_KEY, valid({ version: 99 } as unknown as Partial<JourneyState>))
    expect(createJourneyStore(sessionStorage).getState().step).toBeNull()
    expect(sessionStorage.getItem(STORAGE_KEY)).toBeNull()
  })

  it('fonctionne en mémoire si le stockage est indisponible', () => {
    const broken = {
      getItem: () => {
        throw new Error('SecurityError')
      },
      setItem: () => {
        throw new Error('QuotaExceededError')
      },
      removeItem: () => {
        throw new Error('SecurityError')
      },
    } as unknown as Storage
    const store = createJourneyStore(broken)
    store.setState({ step: 1 })
    expect(store.getState().step).toBe(1)
    expect(() => store.reset()).not.toThrow()
  })

  it('persiste dans sessionStorage sous une clé versionnée', () => {
    const store = createJourneyStore(sessionStorage)
    store.saveDraft(2, { return_reasons: ['accueil'] })
    expect(JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? '{}').drafts).toEqual({ 2: { return_reasons: ['accueil'] } })
  })
})

describe('Divers', () => {
  it('message 429 en minutes', () => {
    expect(tooManyRequestsMessage(3600)).toBe('Trop de tentatives. Réessayez dans 60 minutes.')
    expect(tooManyRequestsMessage(null)).toBe('Trop de tentatives. Réessayez dans quelques minutes.')
  })

  it('génère un QR code SVG avec zone de silence', () => {
    const qr = buildQrSvg('https://registre.exemple.org')
    // Côté = 17 + 4 × version, plus 4 modules de marge de chaque côté.
    expect((qr.size - 8 - 17) % 4).toBe(0)
    expect(qr.path).toMatch(/^M4 4h7v1h-7z/)
  })
})
