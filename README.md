# Lnky Short Links

Plugin WordPress MVP pour creer des liens courts avec :

- slug personnalise ou genere automatiquement
- destination externe
- destination interne via recherche AJAX
- pages, articles, et produits WooCommerce si actifs
- redirection 301, 302 ou 307
- compteur de clics
- configuration domaine + sous-domaine + chemin local de test
- base de configuration SaaS/API pour la future plateforme Lnky

## Domaine par defaut

Le plugin est maintenant preconfigure pour utiliser `lnky.fr` par defaut.

- domaine actif par defaut : `lnky.fr`
- base API prevue par defaut : `https://api.lnky.fr`

## Ce que le plugin sait faire tout seul

En mode autonome WordPress, le plugin sait rediriger :

- via un chemin local, par exemple `/lnky/promo`
- via un host dedie si ce host pointe deja vers le meme WordPress

Exemple :

- domaine actif : `lnky.fr`
- sous-domaine : `seo`
- slug : `audit`

Le plugin affichera `seo.lnky.fr/audit`, mais cette URL ne fonctionnera vraiment que si :

- `seo.lnky.fr` pointe vers le WordPress
- le serveur accepte ce host
- le SSL est configure

## Limite importante

Si tu veux un vrai modele SaaS avec sous-domaines dynamiques du type :

- `abc.lnky.fr/offre`
- `sam.lnky.fr/video`

alors il faudra une infra centrale en plus du plugin :

- wildcard DNS `*.lnky.fr`
- certificat SSL wildcard
- serveur ou API centrale qui connait les sous-domaines et les slugs

Le plugin actuel est donc une excellente base MVP WordPress, mais le mode multi-tenant complet demandera ce service central.

## Feuille de route SaaS

Le plugin contient deja les reglages suivants pour preparer la suite :

- mode `Autonome WordPress`
- mode `SaaS / API centrale`
- champ `Base URL API`
- champ `Cle API`

La prochaine etape sera de brancher de vrais endpoints pour :

- reserver un sous-domaine
- verifier sa disponibilite
- synchroniser les liens courts
- recuperer les stats depuis la plateforme centrale
