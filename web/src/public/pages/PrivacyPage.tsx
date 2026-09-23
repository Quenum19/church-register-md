// Mention d'information (RGPD et loi ivoirienne n° 2013-450 du 19 juin 2013).
// Texte à faire valider par l'Église : les passages [À COMPLÉTER : …] / [À VALIDER : …]
// doivent être renseignés avant la mise en production.

import type { ReactNode } from 'react'
import { Link, useLocation, useNavigate } from 'react-router'
import { buttonSecondary } from '../ui/classes'
import { Shell } from '../ui/Shell'

function Todo({ children }: { children: ReactNode }) {
  return <mark className="rounded bg-church-gold-pale px-1 font-bold text-church-gold-dk">[{children}]</mark>
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="flex flex-col gap-2">
      <h2 className="font-display text-xl font-bold text-church-purple-dk">{title}</h2>
      {children}
    </section>
  )
}

export default function PrivacyPage() {
  const location = useLocation()
  const navigate = useNavigate()
  // Arrivée depuis le formulaire : on y retourne (le brouillon est conservé).
  const canGoBack = location.key !== 'default'

  const back = canGoBack ? (
    <button type="button" className={buttonSecondary} onClick={() => navigate(-1)}>
      Revenir à la page précédente
    </button>
  ) : (
    <Link to="/" className={buttonSecondary}>
      Aller à l'accueil
    </Link>
  )

  return (
    <Shell
      title="Vos données personnelles"
      subtitle={<p>Mention d'information sur l'utilisation des informations recueillies lors de vos visites.</p>}
      privacyLink={false}
    >
      <div className="flex flex-col gap-6 text-gray-900 [&_li]:ml-5 [&_li]:list-disc [&_p]:leading-relaxed">
        <Section title="Qui est responsable de vos données ?">
          <p>
            L'Église La Maison de la Destinée, <Todo>À COMPLÉTER : forme juridique et adresse du siège</Todo>,
            représentée par <Todo>À COMPLÉTER : nom et fonction du responsable du traitement</Todo>.
          </p>
          <p>
            Formalités auprès de l'ARTCI : <Todo>À COMPLÉTER : numéro de déclaration ou d'autorisation</Todo>.
          </p>
        </Section>

        <Section title="Pourquoi ces informations ?">
          <p>
            Uniquement pour le <strong>suivi pastoral des visiteurs</strong> : vous accueillir, vous recontacter si vous
            le souhaitez et vous accompagner lors de vos premières visites. Vos données ne sont jamais vendues, ni
            utilisées à des fins commerciales ou publicitaires.
          </p>
          <p>
            Elles sont traitées avec votre <strong>consentement</strong>, donné en cochant la case prévue lors de votre
            1re visite. Fréquenter une Église peut révéler des convictions religieuses, qui sont des données sensibles
            au sens de la loi n° 2013-450 et du Règlement général sur la protection des données (RGPD) : c'est pourquoi
            votre accord explicite est demandé.
          </p>
        </Section>

        <Section title="Quelles informations ?">
          <ul className="flex flex-col gap-1">
            <li>votre numéro de téléphone, qui vous identifie d'une visite à l'autre ;</li>
            <li>vos nom et prénoms, votre commune et votre quartier ;</li>
            <li>votre numéro WhatsApp et votre souhait de rejoindre le groupe WhatsApp (facultatifs) ;</li>
            <li>la façon dont vous avez connu l'Église et, le cas échéant, le nom et la famille de la personne qui vous a invité(e) ;</li>
            <li>vos réponses aux questions des 2e et 3e visites ;</li>
            <li>les dates de vos visites, la famille qui vous a accueilli(e) et la date de votre consentement.</li>
          </ul>
        </Section>

        <Section title="Qui peut les consulter ?">
          <p>
            Seuls les <strong>responsables habilités de l'Église</strong> (Pasteur, responsables de l'accueil et des
            familles d'accueil), au moyen d'un espace d'administration protégé par mot de passe.
          </p>
          <p>
            Nos prestataires techniques (hébergement du site : <Todo>À COMPLÉTER : hébergeur et pays des serveurs</Todo> ;
            envoi des e-mails aux responsables : <Todo>À COMPLÉTER : prestataire</Todo>) les traitent uniquement pour le
            compte de l'Église. <Todo>À VALIDER : formalités applicables au transfert de données hors de Côte d'Ivoire</Todo>
          </p>
        </Section>

        <Section title="Combien de temps ?">
          <p>
            Vos informations sont conservées <strong>24 mois après votre dernière visite</strong>, puis supprimées
            automatiquement. Si vous devenez membre de l'Église, elles sont conservées tant que vous en êtes membre{' '}
            <Todo>À VALIDER par l'Église</Todo>.
          </p>
        </Section>

        <Section title="Quels sont vos droits ?">
          <p>
            Vous pouvez à tout moment <strong>accéder</strong> à vos données, les faire <strong>rectifier</strong> ou{' '}
            <strong>supprimer</strong>, vous opposer à leur utilisation et <strong>retirer votre consentement</strong>{' '}
            (sans effet sur ce qui a été fait auparavant).
          </p>
          <p>
            Pour exercer ces droits : <Todo>À COMPLÉTER : adresse e-mail et téléphone de contact</Todo>, ou adressez-vous
            à un responsable de l'accueil. Une réponse vous sera apportée dans un délai d'un mois{' '}
            <Todo>À VALIDER</Todo>.
          </p>
          <p>
            Vous pouvez aussi adresser une réclamation à l'ARTCI, autorité de protection des données personnelles en
            Côte d'Ivoire, ou, si vous résidez dans l'Union européenne, à l'autorité de votre pays (en France, la CNIL).
          </p>
        </Section>

        <Section title="Sécurité">
          <p>
            Connexion chiffrée, accès réservé aux personnes habilitées, journal des actions sensibles. Aucune donnée
            personnelle n'est affichée sur les pages publiques du registre.
          </p>
        </Section>

        <p className="text-sm text-gray-700">
          Dernière mise à jour : <Todo>À COMPLÉTER : date de validation par l'Église</Todo>
        </p>

        {back}
      </div>
    </Shell>
  )
}
