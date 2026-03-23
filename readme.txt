=== Lnky Short Links ===
Contributors: azertaf
Tags: short links, redirects, marketing links, woocommerce, admin
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.1.4
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
- connecter directement WordPress a l API Lnky

Le plugin est preconfigure pour utiliser `lnky.fr` comme domaine principal par defaut.

== Installation ==

1. Uploade le dossier `lnky-short-links` dans `/wp-content/plugins/`
2. Active le plugin dans WordPress
3. Ouvre `Lnky Links > Reglages`
4. Configure le domaine, le sous-domaine et la base API
5. Cree ton premier lien

== Frequently Asked Questions ==

= Est-ce que le plugin cree vraiment des sous-domaines tout seul ? =

Non. Il sait utiliser un sous-domaine si celui-ci pointe deja vers le meme WordPress. Pour des sous-domaines dynamiques a grande echelle, il faut une infra centrale avec wildcard DNS et SSL.

= Le plugin est-il deja connecte a l API SaaS ? =

Oui. Le plugin se connecte directement a `api.lnky.fr` et l utilisateur n a pas besoin de saisir un token.

= Quelle redirection choisir : 301, 302 ou 307 ? =

301 = permanente, pour une destination stable.

302 = temporaire, recommandee dans la plupart des cas marketing.

307 = temporaire stricte, plus technique.

= Est-ce que WooCommerce est obligatoire ? =

Non. Le type Produit apparait seulement si WooCommerce est actif.

== Changelog ==

= 0.1.4 =

* Explication ajoutee dans l admin pour aider a choisir entre 301, 302 et 307.

= 0.1.3 =

* Suppression des references au mode autonome dans l interface utilisateur.
* Connexion directe a l API Lnky sans saisie de token utilisateur.
* Experience plugin simplifiee en mode SaaS uniquement.

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
