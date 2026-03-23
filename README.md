# Lnky Short Links

Plugin WordPress MVP v0.1.5 pour creer des liens courts avec :

- slug personnalise ou genere automatiquement
- destination externe
- destination interne via recherche AJAX
- pages, articles, et produits WooCommerce si actifs
- redirection 301, 302 ou 307
- compteur de clics
- configuration domaine + sous-domaine
- connexion directe a l API Lnky
- synchronisation automatique des workspaces et liens

## Domaine par defaut

Le plugin est maintenant preconfigure pour utiliser `lnky.fr` par defaut.

- domaine actif par defaut : `lnky.fr`
- base API prevue par defaut : `https://api.lnky.fr`

## Limite importante

Si tu veux un vrai modele SaaS avec sous-domaines dynamiques du type :

- `abc.lnky.fr/offre`
- `sam.lnky.fr/video`

alors il faudra une infra centrale en plus du plugin :

- wildcard DNS `*.lnky.fr`
- certificat SSL wildcard
- serveur ou API centrale qui connait les sous-domaines et les slugs

Le plugin fonctionne maintenant comme une console SaaS WordPress connectee a l API Lnky.

## Feuille de route SaaS

Le plugin contient deja les reglages suivants :

- domaine
- sous-domaine
- `Base URL API`

La prochaine etape sera de brancher de vrais endpoints pour :

- recuperer les stats depuis la plateforme centrale
- gerer les clics et l analytics avancee
- brancher un vrai certificat wildcard DNS challenge
