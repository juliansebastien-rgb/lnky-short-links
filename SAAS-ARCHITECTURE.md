# Lnky SaaS Architecture

## Vision

Le plugin WordPress reste une console d administration simple.
La logique de marque et de sous-domaines dynamiques vit sur l infrastructure Lnky.

Format cible :

- `seo.lnky.fr/guide`
- `sam.lnky.fr/offre`
- `pro.lnky.fr/devis`

## Repartition des responsabilites

### Plugin WordPress

- onboarding utilisateur
- choix du domaine
- saisie du sous-domaine
- creation des slugs et destinations
- synchronisation avec l API Lnky
- affichage local des liens et stats

### API Lnky

- verifier la disponibilite d un sous-domaine
- reserver un sous-domaine
- stocker les workspaces
- stocker les slugs
- exposer des endpoints de gestion
- remonter les statistiques

### Infra Lnky

- wildcard DNS `*.lnky.fr`
- certificat SSL wildcard
- reverse proxy
- application de redirection

## Endpoints proposes

### `POST /v1/workspaces`

Creation d un espace client :

- `domain`
- `subdomain`
- `site_url`
- `site_name`

### `GET /v1/workspaces/availability`

Verification de disponibilite :

- `domain`
- `subdomain`

### `POST /v1/links`

Creation d un lien :

- `workspace_id`
- `slug`
- `target_mode`
- `target_type`
- `destination_url`
- `destination_post_id`
- `redirect_type`
- `is_active`

### `PATCH /v1/links/{id}`

Modification d un lien existant.

### `GET /v1/links`

Recuperation des liens du workspace.

### `GET /v1/stats`

Recuperation des clics et des stats.

## Strategie de transition

1. V1 actuelle : plugin autonome WordPress avec domaine `lnky.fr` par defaut.
2. V1.1 : plugin connecte a une API, mais redirection locale encore possible pour les tests.
3. V2 : workspaces et sous-domaines entierement geres par Lnky.
4. V3 : analytics avances, QR codes, quotas, multi-domaines.
