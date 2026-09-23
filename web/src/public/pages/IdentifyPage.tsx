// Accueil : saisie du numéro, confirmation, puis POST /api/public/identify.
// Volontairement sans react-hook-form ni zod : c'est la page du bundle initial.

import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useLocation, useNavigate } from 'react-router'
import { DEFAULT_COUNTRY, findCountry, type CountryCode } from '../../shared/domain'
import { ApiError } from '../../shared/http'
import { useJourneyStore } from '../journey/context'
import { toDisplayError, type DisplayError } from '../journey/errors'
import { identify, pathForStep } from '../journey/flow'
import { cleanPhoneInput, formatPhoneWithDial, phoneHint, validatePhone } from '../journey/phone'
import type { PhoneEntry } from '../journey/store'
import { preloadJourneyPages } from '../visit/preload'
import { buttonPrimary, buttonSecondary } from '../ui/classes'
import { Alert, LiveStatus, Spinner } from '../ui/Feedback'
import { CountrySelect, TextField } from '../ui/Field'
import { Shell } from '../ui/Shell'

interface IdentifyLocationState {
  confirm?: boolean
}

export default function IdentifyPage() {
  const store = useJourneyStore()
  const navigate = useNavigate()
  const location = useLocation()

  const [initial] = useState(() => {
    const s = store.getState()
    return s.typed ?? s.identified ?? { country: DEFAULT_COUNTRY, phone: '' }
  })
  const [country, setCountry] = useState<CountryCode>(initial.country)
  const [phone, setPhone] = useState(initial.phone)
  const [phoneError, setPhoneError] = useState<string | null>(null)
  const [requestError, setRequestError] = useState<DisplayError | null>(null)
  const [busy, setBusy] = useState(false)
  const phoneRef = useRef<HTMLInputElement>(null)
  const abortRef = useRef<AbortController | null>(null)
  const focusPhone = useRef(false)
  // Le numéro à confirmer vit dans sessionStorage (pas dans l'historique) : après « Terminer »,
  // un retour arrière ne réaffiche jamais le numéro du visiteur précédent.
  const [typed, setTyped] = useState<PhoneEntry | null>(() => store.getState().typed)
  const confirming = (location.state as IdentifyLocationState | null)?.confirm === true && typed !== null

  // Formulaires et pages de fin sont préchargés pendant la saisie du numéro.
  useEffect(() => {
    const timer = window.setTimeout(preloadJourneyPages, 1500)
    return () => {
      window.clearTimeout(timer)
      abortRef.current?.abort()
    }
  }, [])

  useEffect(() => {
    if (!confirming && focusPhone.current) {
      focusPhone.current = false
      phoneRef.current?.focus()
    }
  }, [confirming])

  const backToInput = () => {
    setRequestError(null)
    if (location.key !== 'default') navigate(-1)
    else navigate('/', { replace: true })
  }

  const onContinue = (event: FormEvent) => {
    event.preventDefault()
    const error = validatePhone(phone, country)
    if (error) {
      setPhoneError(error)
      phoneRef.current?.focus()
      return
    }
    setPhoneError(null)
    setRequestError(null)
    const entry = { country, phone: phone.trim() }
    store.setState({ typed: entry })
    setTyped(entry)
    navigate('/', { state: { confirm: true } satisfies IdentifyLocationState })
  }

  const runIdentify = async () => {
    if (busy || !typed) return
    setBusy(true)
    setRequestError(null)
    const controller = new AbortController()
    abortRef.current = controller
    try {
      const step = await identify(store, typed, controller.signal)
      // Le bouton reste désactivé jusqu'au changement de page (pas de double envoi).
      navigate(pathForStep(step))
    } catch (error) {
      if (controller.signal.aborted) return
      setBusy(false)
      if (error instanceof ApiError && error.status === 422) {
        setPhoneError(error.fieldError('phone') ?? error.fieldError('country') ?? error.message)
        focusPhone.current = true
        backToInput()
        return
      }
      setRequestError(toDisplayError(error))
    }
  }

  const onConfirm = (event: FormEvent) => {
    event.preventDefault()
    void runIdentify()
  }

  if (confirming && typed) {
    const typedCountry = findCountry(typed.country)
    return (
      <Shell
        hero
        title="C'est bien votre numéro ?"
        subtitle={<p>Vérifiez-le attentivement : il vous permettra d'être reconnu(e) lors de vos prochaines visites.</p>}
      >
        <form noValidate onSubmit={onConfirm} className="flex flex-col gap-5">
          <div className="rounded-2xl border-2 border-church-gold bg-church-gold-pale px-5 py-4 text-center">
            <p className="text-sm font-bold tracking-wide text-church-gold-dk uppercase">Votre numéro</p>
            <p className="mt-1 font-display text-2xl font-bold break-words text-church-purple-dk">
              {formatPhoneWithDial(typed.phone, typed.country)}
            </p>
            <p className="mt-1 text-gray-700">{typedCountry.code === 'OTHER' ? 'Numéro international' : typedCountry.name}</p>
          </div>

          {requestError && (
            <Alert
              title="Votre numéro n'a pas pu être vérifié."
              action={
                requestError.retryable && (
                  <button type="button" className={buttonSecondary} onClick={() => void runIdentify()} disabled={busy}>
                    Réessayer
                  </button>
                )
              }
            >
              {requestError.message}
            </Alert>
          )}

          <div className="flex flex-col gap-3">
            <button type="submit" className={buttonPrimary} disabled={busy}>
              {busy && <Spinner />}
              {busy ? 'Vérification…' : "Oui, c'est mon numéro"}
            </button>
            <button type="button" className={buttonSecondary} onClick={backToInput} disabled={busy}>
              Non, modifier le numéro
            </button>
          </div>
          <LiveStatus message={busy ? 'Vérification de votre numéro en cours…' : ''} />
        </form>
      </Shell>
    )
  }

  const selected = findCountry(country)
  return (
    <Shell
      hero
      title="Enregistrez votre visite"
      subtitle={
        <p>
          Ravis de vous accueillir ! Saisissez votre numéro de téléphone : il vous identifiera lors de vos prochaines
          visites.
        </p>
      }
    >
      <form noValidate onSubmit={onContinue} className="flex flex-col gap-5">
        <CountrySelect
          id="country"
          name="country"
          label="Pays du numéro"
          value={country}
          onChange={(event) => {
            const next = event.target.value as CountryCode
            setCountry(next)
            setPhone((current) => cleanPhoneInput(current, next))
            setPhoneError(null)
          }}
        />
        <TextField
          ref={phoneRef}
          id="phone"
          name="phone"
          label="Numéro de téléphone"
          hint={phoneHint(country)}
          error={phoneError ?? undefined}
          type="tel"
          inputMode="tel"
          autoComplete={country === 'OTHER' ? 'tel' : 'tel-national'}
          dialPrefix={country === 'OTHER' ? undefined : selected.dial}
          value={phone}
          onChange={(event) => {
            setPhone(cleanPhoneInput(event.target.value, country))
            if (phoneError) setPhoneError(null)
          }}
        />
        <button type="submit" className={buttonPrimary}>
          Continuer
        </button>
      </form>
    </Shell>
  )
}
