# Statamic Privacy Analytics — Traitement des données

*Document destiné à un client, un responsable de projet ou un conseil juridique. Il décrit le fonctionnement de l'outil de statistiques installé sur votre site, sans entrer dans le détail technique.*

## En une phrase

Vos données de fréquentation restent sur votre propre serveur. Aucune n'est transmise à Google, Matomo, Plausible ou un autre service tiers, sauf si vous activez vous-même une option spécifique décrite plus bas.

## Quelles données sont collectées

À chaque visite d'une page de votre site, l'outil enregistre :

- la page consultée (sans les paramètres présents dans l'URL, par exemple une recherche ou un identifiant de session)
- l'adresse IP du visiteur, temporairement
- le type d'appareil, le navigateur et le système d'exploitation
- le pays et la ville, déduits de l'adresse IP
- la page d'où provient le visiteur (site précédent), simplifiée pour ne conserver que le nom de domaine
- un identifiant de session propre à l'outil, qui ne permet pas d'identifier une personne

Si un visiteur est connecté à votre site (compte client, back-office), son identifiant de compte peut être associé temporairement à sa visite. Cette option est activée par défaut mais peut être désactivée sur demande.

## Combien de temps ces données sont conservées

Deux mécanismes distincts s'appliquent automatiquement, sans intervention nécessaire de votre part :

**Après 90 jours** — l'adresse IP, les informations techniques précises (navigateur détaillé) et l'identifiant de compte sont effacés. Les statistiques globales (nombre de visites, pays, type d'appareil) restent disponibles, mais ne peuvent plus être reliées à une IP ou à une personne précise.

**Après 180 jours** — la ligne détaillée de chaque visite est supprimée définitivement. Seuls les totaux journaliers agrégés (visites, visiteurs, pays, appareils) restent disponibles indéfiniment, sous une forme qui ne permet plus de reconstituer le parcours d'un visiteur individuel.

Ces deux durées sont configurables selon vos besoins.

## Où sont hébergées ces données

Sur le serveur qui héberge déjà votre site. Aucune infrastructure supplémentaire n'est nécessaire, aucun compte externe n'est créé.

**Localisation géographique** — pour la détection du pays et de la ville d'un visiteur, deux options existent :

- **Par défaut** : une base de données téléchargée une fois sur votre serveur (MaxMind GeoLite2). Aucune adresse IP ne quitte votre serveur pour cette opération.
- **Option alternative** (à activer volontairement) : un service externe (ip-api.com), qui reçoit alors l'adresse IP du visiteur pour la traduire en pays/ville. Cette option n'est pas active par défaut sur votre installation, sauf demande contraire de votre part.

## Qui, dans votre équipe, peut voir ces données

L'accès au tableau de bord est protégé par deux niveaux de droits, attribuables individuellement à chaque compte de votre équipe :

- **Consultation** — accès au tableau de bord et aux statistiques.
- **Export et gestion** — en plus de la consultation, la possibilité d'exporter les données brutes (incluant les adresses IP non encore anonymisées) dans un fichier, et de réinitialiser certains compteurs internes.

Un compte qui n'a ni l'un ni l'autre de ces droits ne voit pas le tableau de bord dans son interface d'administration.

## Cookies

Aucun cookie propre à l'outil de statistiques n'est déposé sur le navigateur du visiteur. Le suivi s'appuie sur le mécanisme de session déjà utilisé par votre site pour son fonctionnement normal (panier, connexion, etc.), lequel peut lui-même reposer sur un cookie technique classique — indépendant de cet outil.

Un bandeau de consentement optionnel peut être activé si vous souhaitez conditionner le suivi statistique à l'accord explicite du visiteur.

## En résumé, ce que ça change pour vous

- Pas de sous-traitant à déclarer dans votre registre de traitement pour la fonction statistique, sauf si vous activez volontairement l'option de géolocalisation externe.
- Pas de transfert de données hors de Suisse (ou du pays d'hébergement de votre site) pour cette fonctionnalité.
- Pas d'abonnement ni de facturation liée au volume de trafic.
- Les données peuvent être supprimées ou leur durée de conservation ajustée sur simple demande.

*Pour toute question technique complémentaire, la documentation complète du logiciel est disponible sur [github.com/oliweb-ch/statamic-privacy-analytics](https://github.com/oliweb-ch/statamic-privacy-analytics).*
