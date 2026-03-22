# Deploy notes

Ce dossier contient une base de deploiement **a etudier avant application**.

Objectif :

- ne rien casser sur le VPS existant
- reutiliser le Traefik deja en place
- ajouter `lnky.fr`, `www.lnky.fr` et `*.lnky.fr` de facon isolee

## Principe

Le serveur actuel utilise deja Traefik sur `80` et `443`.
Il ne faut donc pas lancer un nouveau reverse proxy frontal.

La bonne approche est :

1. laisser la stack `infra` telle quelle
2. etendre l API existante pour accepter `api.lnky.fr`
3. creer une nouvelle stack `lnky` separee pour le service de redirection

## Attention SSL

La configuration actuelle Traefik utilise un `httpChallenge`.
Cela fonctionne pour :

- `lnky.fr`
- `www.lnky.fr`
- `api.lnky.fr`
- des sous-domaines visites un par un

Pour un vrai certificat wildcard `*.lnky.fr`, il faudra passer plus tard a un `dnsChallenge`.
