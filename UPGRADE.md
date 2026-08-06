# Upgrade Guide

## Upgrading to 2.0

### Breaking change : default geolocation provider changed from `ip-api` to `maxmind`

Prior to 2.0, the addon used `ip-api` (external HTTP calls) as the default geolocation
provider. Starting with 2.0, the default is `maxmind` (local GeoLite2 database, no
external calls).

#### Who is affected?

You are affected if **you never set `ANALYTICS_GEO_PROVIDER` in your `.env`** and
relied on the implicit `ip-api` default. After upgrading, the addon will switch to
`maxmind` automatically.

#### Symptoms if MaxMind is not configured

- A warning banner will appear in the Control Panel analytics dashboard.
- Geolocation will silently return empty results (no country/city data recorded).
- Top countries and Top cities widgets will remain empty (but still visible).

#### Options

**Option A — Keep using `ip-api` (no credentials required):**
```
# .env
ANALYTICS_GEO_PROVIDER=ip-api
```

**Option B — Set up MaxMind GeoLite2 (recommended, full privacy):**
```
# .env
ANALYTICS_GEO_PROVIDER=maxmind
MAXMIND_ACCOUNT_ID=<your_account_id>
MAXMIND_LICENSE_KEY=<your_license_key>
```
Then download the database:
```bash
php artisan analytics:update-geoip
```
See the [README](README.md#maxmind-geolite2-recommended-for-full-privacy) for the
full setup procedure.

**Option C — Disable geolocation entirely:**
```
# .env
ANALYTICS_GEO_PROVIDER=disabled
```
Geographic widgets (Top countries, Top cities) will be hidden from the dashboard.
No IP data is sent to any external service.

---

### What does NOT change

- The `ip-api` and `disabled` providers continue to work exactly as before when
  explicitly configured via `ANALYTICS_GEO_PROVIDER`.
- Retrocompat: if your published config still uses the old boolean key `enabled`
  instead of `provider`, it continues to be honoured (`enabled=true` → `ip-api`,
  `enabled=false` → `disabled`). Migrate to `provider` at your next
  `vendor:publish --force`.
- All other configuration keys, dashboard widgets (non-geographic), and scheduled
  commands are unchanged.

---

### Composer constraint

Users installing via `^1.x` will **not** receive this update automatically — Composer
semver guarantees that. To upgrade explicitly:
```bash
composer require oliweb/statamic-privacy-analytics:^2.0
```

---

## Upgrading to 3.0

**Breaking change (destructive)** : une politique de rétention des IP est désormais
active par défaut. Les adresses IP et user-agents des pages vues de plus de 90 jours
seront automatiquement et irréversiblement anonymisés (mis à NULL) par une commande
planifiée quotidienne.

#### Who is affected?

Toute installation existante, dès la mise à jour vers 3.0 et le premier passage du
scheduler après upgrade.

#### What does NOT change

- Les statistiques de fréquentation (visites, pages vues, visiteurs uniques) restent
  intactes : elles reposent sur `visitor_id`/`session_id`, pas sur `ip_address`.
- Les données de géolocalisation déjà résolues (`country_code`, `city`) sur les pages
  vues existantes ne sont **pas** affectées — seules les colonnes `ip_address` et
  `user_agent` sont mises à NULL.

#### Database migration

La colonne `ip_address` devient nullable. Exécutez les migrations après la mise à
jour du package :

```bash
php artisan migrate
```

#### Options

**Option A — Conserver le comportement par défaut (recommandé, conforme RGPD/nLPD) :**
Rien à faire. La rétention de 90 jours s'applique automatiquement.

**Option B — Ajuster la durée de rétention :**
```
# .env
ANALYTICS_IP_RETENTION_DAYS=30
```
Ou dans `config/statamic-analytics.php` (après `vendor:publish`) :
```php
'privacy' => [
    'ip_retention_days' => 30,
],
```

**Option C — Désactiver l'anonymisation automatique (déconseillé) :**
```
# .env
ANALYTICS_IP_RETENTION_DAYS=null
```
> ⚠️ Le mot `null` doit être écrit littéralement, sans guillemets. Une valeur vide
> (`ANALYTICS_IP_RETENTION_DAYS=`) est interprétée par Laravel comme une chaîne
> vide, pas comme `null`, et ne désactive PAS l'anonymisation — elle provoquerait
> au contraire une anonymisation immédiate de la quasi-totalité des données
> existantes au prochain passage du scheduler.

Ou explicitement dans la config :
```php
'privacy' => [
    'ip_retention_days' => null,
],
```
> ⚠️ La conservation illimitée des IPs est non conforme au RGPD/nLPD par défaut.
> N'activez cette option qu'en connaissance de cause et avec une base légale appropriée.

#### Anonymisation manuelle

Pour déclencher une anonymisation immédiate (sans attendre le scheduler) :
```bash
# Prévisualiser les lignes concernées
php artisan analytics:anonymize-ips --dry-run

# Anonymiser
php artisan analytics:anonymize-ips
```

---

### Composer constraint

Users installing via `^2.x` will **not** receive this update automatically — Composer
semver guarantees that. To upgrade explicitly:
```bash
composer require oliweb/statamic-privacy-analytics:^3.0
```

---

## Upgrading to 4.0

### Breaking change : purge définitive des événements bruts activée par défaut

À partir de la v4.0, une nouvelle commande planifiée quotidienne (`analytics:purge-raw-events`) supprime **définitivement et irréversiblement** les lignes de `statamic_analytics_page_views` de plus de 180 jours. C'est une suppression de lignes, pas une anonymisation : les données ne sont pas récupérables.

#### Who is affected?

Toute installation existante avec des données de plus de 180 jours, dès le premier passage du scheduler après la mise à jour.

#### Ce qui est préservé indéfiniment

Les agrégats quotidiens dans `statamic_analytics_aggregates` **ne sont pas affectés** par la purge. La dimension `_overview` (nouvelle en v4.0) conserve par jour :
- Visites totales, visiteurs uniques, pages vues uniques, visiteurs récurrents

Les dimensions existantes (pays, appareil, navigateur, plateforme) sont également préservées.

#### Ce qui disparaît au-delà de la fenêtre de rétention brute

Les widgets suivants afficheront des données vides pour les dates antérieures à la fenêtre :
pages individuelles, sources de trafic, referrers, heatmap horaire, profondeur de session, temps moyen par page, flux utilisateurs.

#### Options

**Option A — Conserver le comportement par défaut (recommandé) :**
Rien à faire. La rétention brute de 180 jours s'applique automatiquement.

**Option B — Ajuster la durée :**
```
# .env
ANALYTICS_RAW_RETENTION_DAYS=365
```

**Option C — Désactiver la purge automatique (déconseillé) :**
```
# .env
ANALYTICS_RAW_RETENTION_DAYS=null
```
> ⚠️ Le mot `null` doit être écrit littéralement. Une valeur vide (`ANALYTICS_RAW_RETENTION_DAYS=`) est lue par Laravel comme une chaîne vide et non comme `null` — la garde défensive la traite comme une rétention illimitée, ce qui est le comportement attendu, mais l'intention reste ambiguë à la lecture du fichier `.env`.

#### Avant de mettre à jour

Faire un `--dry-run` pour évaluer l'impact sur vos données existantes :
```bash
php artisan analytics:purge-raw-events --dry-run
```

### Autres changements v4.0

- **Route CP renommée** : `statamic-analytics.clear-cache` → `statamic-analytics.reset-stats` (depuis v3.2.0, inclus ici pour signaler le changement d'API).
- **`AnalyticsSettingsController` supprimé** : contrôleur sans route ni vue, résidu du fork.
- **ip-api** : `file_get_contents` remplacé par la façade `Http` (timeout 2 s / connect 1 s).
- **Session `visited_pages` bornée** à 20 entrées maximum.
- **`_overview` dans les agrégats** : `analytics:process` écrit désormais un résumé journalier sans groupement (dimension `_overview`, dimension_value `_all`).

### Composer constraint

```bash
composer require oliweb/statamic-privacy-analytics:^4.0
```
