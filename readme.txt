=== Lnky Short Links ===
Contributors: azertaf
Tags: short links, redirects, marketing links, woocommerce, admin
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Creer des liens courts avec slugs personnalises, destinations externes ou contenus WordPress, et compteur de clics.

== Description ==

Lnky Short Links ajoute une interface d administration simple pour :

- choisir un domaine de marque
- definir un sous-domaine court
- creer des slugs personnalises
- rediriger vers une URL externe
- rediriger vers une page, un article ou un produit WooCommerce
- compter les clics
- preparer une future connexion a l API Lnky

Le plugin est preconfigure pour utiliser `lnky.fr` comme domaine principal par defaut.

Le plugin inclut aussi un chemin local de test pour faire fonctionner les redirections sans infra externe.

== Installation ==

1. Uploade le dossier `lnky-short-links` dans `/wp-content/plugins/`
2. Active le plugin dans WordPress
3. Ouvre `Lnky Links > Reglages`
4. Configure le domaine, le sous-domaine et le chemin local de test
5. Cree ton premier lien

== Frequently Asked Questions ==

= Est-ce que le plugin cree vraiment des sous-domaines tout seul ? =

Non. Il sait utiliser un sous-domaine si celui-ci pointe deja vers le meme WordPress. Pour des sous-domaines dynamiques a grande echelle, il faut une infra centrale avec wildcard DNS et SSL.

= Le plugin est-il deja pret pour une API SaaS ? =

Oui, les reglages de base sont deja prevus : mode API, base URL API et cle API. Les endpoints restent a brancher dans une prochaine version.

= Est-ce que WooCommerce est obligatoire ? =

Non. Le type Produit apparait seulement si WooCommerce est actif.

== Changelog ==

= 0.1.2 =

* Synchronisation optionnelle des workspaces et liens avec l API Lnky.
* Etat API affiche dans les reglages du plugin.
* Sauvegarde locale preservee meme si l API ne repond pas.

= 0.1.1 =

* Apercus d URLs mis a jour en direct dans l admin selon le domaine, le sous-domaine, le chemin local et le slug.
* Alignement de la documentation avec lnky.fr et api.lnky.fr.

= 0.1.0 =

* Premiere version MVP autonome.
* Reglages domaine, sous-domaine et chemin local.
* Liens courts avec slugs personnalises ou automatiques.
* Destinations externes et contenus WordPress internes.
* Recherche AJAX pages, articles et produits WooCommerce.
* Redirections 301, 302, 307 et compteur de clics.
