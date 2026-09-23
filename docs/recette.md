# Recette — à faire avant d'ouvrir le registre aux visiteurs

Site : **https://registre.newinechurch.org** · Dashboard : **/admin**

## L'objectif

Vérifier qu'**un visiteur réel, sur son propre téléphone, seul, au milieu du monde, avec un réseau
moyen, arrive à s'enregistrer en moins d'une minute** — et que l'équipe d'accueil sait se servir du
dashboard. Les 1 127 tests automatiques prouvent que le code fait ce qu'on lui demande ; la recette
vérifie ce qu'aucun test ne peut voir : la lisibilité au soleil, la compréhension des questions, la
réaction du réseau mobile, et l'usage réel par les accueillantes.

Testez donc **sur un vrai téléphone, en 4G (pas en Wi-Fi), depuis le QR code imprimé**, et si
possible en faisant essayer quelqu'un qui n'a jamais vu l'application.

---

## A. Parcours visiteur (sur téléphone)

| | Test | Attendu |
|---|---|---|
| A1 | Scanner le QR code imprimé | La page d'accueil s'ouvre, logo et nom de l'église lisibles |
| A2 | Saisir un numéro ivoirien **avec les espaces** (`07 00 00 00 00`) | Les 10 chiffres passent (c'était un bug bloquant de l'ancienne version) |
| A3 | Écran de confirmation du numéro | Le numéro affiché est bien celui saisi ; « Modifier » y ramène |
| A4 | Envoyer le formulaire **vide** | Résumé d'erreurs en haut, chaque champ signalé, focus placé sur le résumé |
| A5 | Source « Invité(e) par un membre » | Le nom de l'invitant devient obligatoire, la famille est proposée |
| A6 | Source « Autre » | Un champ de précision apparaît et devient obligatoire |
| A7 | Cocher « rejoindre le groupe WhatsApp » sans numéro | Message clair exigeant un numéro |
| A8 | Cocher « même numéro que celui saisi » | Le champ WhatsApp se remplit tout seul |
| A9 | Ouvrir « Vos données et vos droits » puis revenir | **La saisie est conservée** |
| A10 | Ne pas cocher le consentement | L'envoi est refusé |
| A11 | Envoyer | Page de remerciement, « Votre 1re visite est enregistrée », famille du mois affichée |
| A12 | Rafraîchir la page en pleine saisie | L'étape et les réponses sont restaurées |
| A13 | Bouton retour du téléphone | Reste dans l'application, ne quitte pas le site |
| A14 | Aller directement sur `/visite/2` sans parcours | Redirection vers l'accueil |
| A15 | Rescanner le QR avec **le même numéro, le même jour** | « Votre visite d'aujourd'hui est déjà enregistrée » |
| A16 | Mode avion pendant l'envoi, puis réessayer | Message clair, bouton Réessayer, et **aucun doublon créé** |
| A17 | Un autre pays (France, `06 …`) | Accepté |
| A18 | Un numéro manifestement faux (`00 00`) | Refusé avec un message compréhensible |
| A19 | Lire l'écran **en plein soleil, à bout de bras** | Textes et boutons lisibles, cibles faciles à toucher |
| A20 | Faire essayer une personne non initiée, sans aide | Elle finit seule, sans poser de question |

**Deuxième et troisième visites** : elles ne sont possibles qu'un **autre jour** (une visite par
jour, volontairement). Pour les tester tout de suite, voir la section E.

**Page tablette `/qrcode`** : le QR s'affiche en grand avec le verset, et l'écran ne s'éteint pas.

---

## B. Dashboard (ordinateur, puis téléphone)

| | Test | Attendu |
|---|---|---|
| B1 | Connexion avec un mauvais mot de passe | « Identifiants incorrects. », sans préciser si le compte existe |
| B2 | Connexion correcte | Accueil avec les statistiques |
| B3 | Accueil | Total, nouveaux du jour, visites du mois, famille du mois (**Force** en septembre 2026) |
| B4 | Visiteurs : rechercher `+225 07` | Résultats, **aucune erreur** (l'ancienne version plantait) |
| B5 | Combiner statut + famille + période + recherche | Les filtres s'appliquent **ensemble** |
| B6 | « Tous sauf membres » | Aucun membre dans la liste |
| B7 | Pagination, puis retour depuis une fiche | Les filtres et la page sont conservés |
| B8 | Fiche visiteur | Coordonnées, frise des visites, réponses lisibles en clair |
| B9 | Ajouter puis supprimer une note | Horodatée, avec votre nom |
| B10 | Modifier commune/quartier | Enregistré |
| B11 | Bouton « Convertir en membre » sur un visiteur à 1 visite | Désactivé, avec l'explication |
| B12 | Exports CSV, Excel, PDF | Les trois se téléchargent et s'ouvrent ; les accents sont corrects |
| B13 | Paramètres → verset → changer | La page publique `/qrcode` affiche le nouveau verset |
| B14 | Paramètres → rotation des familles | Les 12 mois s'affichent, modifiables |
| B15 | Paramètres → changer votre mot de passe | Vos autres sessions sont déconnectées |
| B16 | Paramètres → activer la double authentification | QR à scanner (Google Authenticator, Authy), puis code demandé à la connexion suivante |
| B17 | Page QR code → télécharger le PNG et imprimer | L'affiche est correcte et lisible |
| B18 | Journal d'audit | Vos actions ci-dessus y figurent |
| B19 | Refaire B2 à B8 **sur téléphone** | Menu en tiroir, tableaux en cartes, rien ne déborde |

---

## C. Rôles

À faire une fois Brevo configuré (les invitations partent par e-mail) :

1. Inviter un **modérateur** et un **lecteur** (Administrateurs → Nouvel administrateur).
2. Avec le **lecteur** : il voit les listes, mais **aucun** bouton Modifier, Convertir, Supprimer ni Exporter.
3. Avec le **modérateur** : il peut ajouter une note et modifier une fiche, mais **pas** convertir, supprimer, ni gérer les administrateurs.
4. Essayer de se rétrograder soi-même ou de supprimer le dernier super_admin : refusé avec un message clair.

---

## D. Ce qui ne peut pas encore être testé

- **Tous les e-mails** (invitations, mot de passe oublié, rapports) : ils sont écrits dans les
  journaux du serveur tant que Brevo n'est pas configuré.
- **Le rapport mensuel automatique** : il part le 1er du mois à 8 h. Pour le déclencher à la
  demande : Rapports → mois précédent → Envoyer.
- **La purge des données à 24 mois** : rien à purger avant 2028.

---

## E. Créer des données de test réalistes

Pour tester les 2e et 3e visites, la conversion en membre et les rapports sans attendre trois
dimanches, depuis PowerShell :

```powershell
ssh -i C:\Users\slim3\.ssh\church-register-deploy -p 65002 -t u781799599@46.202.183.6 "cd ~/church-register/current && php artisan db:seed --class=DemoSeeder --force"
```

Cela crée **60 visiteurs fictifs** répartis sur 12 mois, avec 1 à 3 visites et quelques membres :
de quoi éprouver les filtres, les exports et les rapports. Ces données sont clairement fictives
(noms ivoiriens générés, numéros inexistants).

---

## F. Nettoyage avant l'ouverture réelle

**Important** : tout ce que vous enregistrez pendant la recette reste en base. Avant le premier
dimanche, remettez la base à zéro puis recréez votre compte :

```powershell
ssh -i C:\Users\slim3\.ssh\church-register-deploy -p 65002 -t u781799599@46.202.183.6 "cd ~/church-register/current && php artisan migrate:fresh --force --seed && php artisan admin:create"
```

Cette commande **efface tout** (visiteurs, membres, notes, journal, comptes), recrée les 7 familles,
les rotations et les paramètres, puis vous redemande votre compte administrateur. Le verset et le
nom de l'église reviennent à leurs valeurs par défaut : reconfigurez-les si vous les aviez changés.

---

## G. Critères pour ouvrir

- [ ] A1 à A20 passés sur un vrai téléphone en 4G
- [ ] Une personne non initiée s'est enregistrée seule, en moins d'une minute
- [ ] B1 à B19 passés, sur ordinateur et sur téléphone
- [ ] Mention d'information **complétée** (elle contient encore des `[À COMPLÉTER]`) et validée
- [ ] Formalités ARTCI vérifiées
- [ ] Brevo configuré, un e-mail de test reçu (Paramètres → Destinataires → Tester)
- [ ] Rôles vérifiés (section C)
- [ ] Sauvegardes quotidiennes activées dans hPanel, **et une restauration testée**
- [ ] QR code imprimé, affiché à l'entrée, testé par au moins deux téléphones différents
- [ ] Base remise à zéro (section F)
- [ ] Les accueillantes ont été formées : elles savent orienter un visiteur et consulter le dashboard

---

## Si quelque chose ne va pas

Notez : **ce que vous faisiez, ce que vous attendiez, ce qui s'est passé**, avec une capture d'écran
et l'heure. Le journal d'audit et `~/church-register/shared/storage/logs/` permettent de retrouver
l'événement côté serveur. La section 15 de [deploiement.md](deploiement.md) couvre les pannes
courantes, et le retour arrière se fait avec `bash deploy/remote-deploy.sh --rollback`.
