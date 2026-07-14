# SIM Auto Workshop - Documentation Projet

## Objectif

Cette application est une petite application Symfony pour la gestion interne de SIM Auto.
Elle n'est pas concue comme un site vitrine. C'est une application metier simple, en arabe RTL, centree sur quatre piliers:

1. Entree des produits dans le stock.
2. Gestion et suivi du stock.
3. Creation des operations de garage: reparations, services, pieces.
4. Cycle devis -> bon de commande -> facture, avec TVA et impression des receipts.

L'application utilise une interface volontairement simple: login minimal, tableau de bord avec quatre grandes entrees, puis une page dediee par module.

## Stack Technique

- Backend: Symfony 7.
- Templates: Twig.
- Base de donnees: SQLite via PDO natif.
- Serveur applicatif: PHP-FPM 8.3.
- Serveur web: Nginx.
- Environnement: Docker Compose.
- Frontend: CSS et JavaScript simples dans `public/`.

Il n'y a pas Doctrine ORM dans cette version. Le service `App\Service\AppDatabase` gere directement la base SQLite avec PDO pour garder l'application legere et facile a deployer.

## Lancement

Depuis le dossier du projet:

```powershell
cd C:\projects\simauto-workshop
docker-compose up -d --force-recreate
```

Puis ouvrir:

```text
http://localhost:8090/login
```

Le service PHP cree automatiquement les dossiers `data` et `var`, ajuste les droits, puis lance PHP-FPM. Composer n'est relance au demarrage que si `vendor/autoload.php` n'existe pas.

## Comptes et Roles

Deux roles existent:

- `admin`: acces au dashboard, produits, stock, operations, factures, receipts, categories, parametres vehicules et gestion complete des utilisateurs.
- `manager`: acces au dashboard, produits, stock, operations, factures et receipts. Le manager peut supprimer les enregistrements non references.

Des comptes initiaux sont crees automatiquement si la table `users` est vide:

- `admin@simauto.ma` avec le role `admin`.
- `manager@simauto.ma` avec le role `manager`.

Les mots de passe initiaux sont stockes avec `password_hash(..., PASSWORD_BCRYPT)`. Important avant production reelle: les changer au premier login depuis la page de changement de mot de passe.

## Organisation Generale

```text
C:\projects\simauto-workshop
|-- bin/
|-- config/
|-- data/
|-- docker/
|-- public/
|-- src/
|-- templates/
|-- var/
|-- vendor/
|-- composer.json
|-- docker-compose.yml
|-- README.md
|-- PROJECT.md
```

## Docker

### `docker-compose.yml`

Definit deux services:

- `php`: construit une image PHP-FPM depuis `docker/php/Dockerfile`.
- `nginx`: utilise `nginx:alpine` et expose le port `8090`.

Le service `php` utilise:

```yaml
APP_ENV: prod
APP_DEBUG: 0
```

Cela evite les pages debug Symfony en usage normal.

La commande de demarrage du service PHP:

```sh
umask 0000 &&
mkdir -p data var/cache var/log &&
rm -rf var/cache/prod &&
chmod -R a+rwX data var &&
if [ ! -f vendor/autoload.php ]; then composer install --no-interaction --prefer-dist; fi &&
php-fpm
```

### `docker/php/Dockerfile`

Base: `php:8.3-fpm`.

Installe:

- `git`
- `curl`
- `zip`
- `unzip`
- `libicu-dev`
- `libzip-dev`

Extensions PHP installees:

- `intl`
- `opcache`
- `zip`

Composer est copie depuis l'image officielle `composer:latest`.

### `docker/nginx/default.conf`

Configure Nginx pour servir `public/` et transmettre les requetes PHP a `php:9000`.

## Symfony et Configuration

### `composer.json`

Dependances principales:

- `symfony/framework-bundle`
- `symfony/twig-bundle`
- `symfony/asset`
- `symfony/console`
- `symfony/dotenv`
- `symfony/yaml`
- `twig/twig`
- `twig/extra-bundle`

Dependances dev:

- `symfony/debug-bundle`
- `symfony/maker-bundle`
- `symfony/var-dumper`

### `public/index.php`

Point d'entree HTTP. Il charge l'autoload Composer, charge `.env` si disponible, cree le Kernel Symfony, traite la requete et envoie la reponse.

### `bin/console`

Point d'entree CLI Symfony pour les commandes comme:

```powershell
docker-compose exec -T php php bin/console cache:clear
```

### `config/services.yaml`

Active autowiring/autoconfigure pour `App\`.

Ajoute un binding important:

```yaml
string $projectDir: '%kernel.project_dir%'
```

Ce binding permet au service `AppDatabase` de connaitre le dossier racine du projet pour creer et lire `data/simauto.sqlite`.

### `config/routes.yaml`

Charge les routes par attributs depuis `src/Controller`.

### `config/packages/framework.yaml`

Configure le framework Symfony, notamment:

- le secret d'application,
- les sessions,
- les erreurs PHP.

### `config/packages/twig.yaml`

Configure Twig avec le dossier `templates/`.

## Controllers

### `src/Controller/AuthController.php`

Gere l'authentification simple par session.

Routes:

- `GET /login`: affiche le formulaire de connexion.
- `POST /login`: verifie email et mot de passe.
- `GET /logout`: vide la session et redirige vers login.

La connexion utilise:

```php
$request->getSession()->set('user', [...])
```

Il ne s'agit pas du composant Security complet de Symfony. C'est un login leger, adapte a cette version simple.

Regles ajoutees:

- seuls les utilisateurs actifs peuvent se connecter,
- 5 echecs de connexion bloquent temporairement les nouvelles tentatives pendant 60 secondes,
- les anciens mots de passe en clair sont re-hashes automatiquement en bcrypt apres un login reussi,
- les sessions sont revalidees a chaque page depuis la base; un compte desactive est deconnecte au prochain chargement.

### `src/Controller/DashboardController.php`

Gere toutes les pages metier.

Routes principales:

- `GET /`: dashboard principal.
- `GET /products`: page d'entree et liste des produits.
- `GET /products/{id}`: fiche detail produit.
- `GET /stock`: page de gestion du stock.
- `GET /operations`: page de creation d'une operation garage.
- `GET /billing`: page des factures et receipts.
- `GET /clients/{id}`: fiche client avec vehicules et dernieres operations.
- `GET /suppliers/{id}`: fiche fournisseur avec dernieres entrees stock.
- `GET /vehicles/{id}`: fiche vehicule avec client proprietaire et historique operations.
- `GET /categories/{id}`: fiche categorie avec produits lies.
- `GET /vehicle-brands/{id}`: fiche marque avec modeles lies.
- `GET /vehicle-models/{id}`: fiche modele.
- `GET /users`: liste et actions utilisateurs, reservee admin.
- `GET|POST /users/new`: creation utilisateur, reservee admin.
- `GET|POST /users/{id}/edit`: modification nom/email/role/statut, reservee admin.
- `GET|POST /users/{id}/password`: reinitialisation mot de passe par admin.
- `POST /users/{id}/toggle`: activation/desactivation, reservee admin.
- `GET|POST /profile/password`: changement du mot de passe personnel pour admin et manager.

Routes d'action:

- `POST /products/new`: ajoute un produit.
- `POST /stock/in`: ajoute une entree de stock.
- `POST /operations/new`: cree une operation garage et redirige vers la facture.

Routes documentaires:

- `GET /invoice/{id}`: affiche une facture imprimable.
- `GET /receipt/{id}`: affiche un ticket receipt imprimable.

Chaque route verifie la session avec la methode privee:

```php
requireUser(Request $request)
```

Si aucun utilisateur n'est connecte, l'application redirige vers `/login`.

## Base de Donnees

### Emplacement

La base SQLite est stockee ici:

```text
data/simauto.sqlite
```

Ce fichier est cree automatiquement au premier acces si absent.

### Service

Toute la logique base de donnees est dans:

```text
src/Service/AppDatabase.php
```

Le constructeur:

1. Cree le dossier `data`.
2. Ouvre la base SQLite.
3. Active les foreign keys.
4. Lance `migrate()`.
5. Lance `seed()`.

La migration est idempotente: elle cree les nouvelles tables si elles n'existent pas, ajoute les colonnes manquantes aux anciennes tables, puis migre les anciennes valeurs texte vers les nouvelles entites normalisees. Le fichier `data/simauto.sqlite` existant n'est pas supprime.

### Tables

#### `users`

Stocke les utilisateurs.

Champs:

- `id`
- `email`
- `password`
- `name`
- `role`: `admin` ou `manager`
- `active`
- `created_at`

#### `products`

Stocke les produits et pieces du stock.

Champs:

- `id`
- `sku`
- `name`
- `category`
- `category_id`
- `stock_qty`
- `min_qty`
- `purchase_price`
- `sale_price`
- `product_type`: `stockable` ou `service`
- `margin_rate`
- `active`
- `created_at`

#### `categories`

Stocke les familles de produits.

Champs:

- `id`
- `name`
- `active`
- `created_at`

#### `suppliers`

Stocke les fournisseurs utilises dans les entrees de stock.

Champs:

- `id`
- `name`
- `phone`
- `email`
- `address`
- `active`
- `created_at`

#### `clients`

Stocke les clients particuliers et societes.

Champs:

- `id`
- `type`: `individual` ou `company`
- `name`
- `phone`
- `email`
- `address`
- `ice`
- `vat`
- `rc`
- `active`
- `created_at`

#### `vehicle_brands`

Stocke les marques de vehicules.

Champs:

- `id`
- `name`
- `active`

#### `vehicle_models`

Stocke les modeles lies a une marque.

Champs:

- `id`
- `brand_id`
- `name`
- `active`

#### `vehicles`

Stocke les vehicules des clients.

Champs:

- `id`
- `client_id`
- `plate`
- `brand_id`
- `model_id`
- `year`
- `mileage`
- `notes`
- `active`
- `created_at`

#### `stock_movements`

Historique des mouvements de stock.

Champs:

- `id`
- `product_id`
- `movement_type`: `in` ou `out`
- `quantity`
- `note`
- `created_by`
- `supplier_id`
- `unit_cost`
- `created_at`

#### `operations`

Operation garage complete.

Champs:

- `id`
- `invoice_no`
- `receipt_no`
- `client_id`
- `client_name`
- `client_address`
- `vehicle_id`
- `vehicle_plate`
- `vehicle_brand`
- `vehicle_model`
- `payment_method`
- `check_number`: numero de cheque optionnel quand `payment_method = CHQ`
- `total`
- `doc_type`: `quote`, `order` ou `invoice`
- `quote_no`
- `order_no`
- `subtotal_ht`
- `vat_rate`
- `vat_amount`
- `total_ttc`
- `parent_id`
- `status`
- `created_by`
- `created_at`

#### `operation_items`

Lignes d'une operation.

Champs:

- `id`
- `operation_id`
- `product_id`
- `line_type`: `product` ou `service`
- `label`
- `quantity`
- `unit_price`
- `discount_rate`
- `total_ht`
- `total`

## Flux Metier

## Internationalisation

L'interface utilise `symfony/translation`.

- Fichiers: `translations/messages.ar.yaml` et `translations/messages.fr.yaml`.
- Locale par defaut: `ar`.
- Selecteur `AR | FR` dans le menu profil de la topbar.
- Le choix est conserve en session et dans le cookie `simauto_locale`.
- `templates/base.html.twig` adapte `lang` et `dir`.
- Les documents imprimes restent en francais LTR.

### 1. Entree Produit

Page:

```text
/products
```

L'utilisateur saisit:

- code produit,
- nom,
- categorie,
- quantite initiale,
- seuil minimum,
- prix d'achat,
- prix de vente.

L'application:

1. Cree le produit dans `products`.
2. Cree un mouvement d'entree dans `stock_movements`.

Le produit est rattache a une categorie obligatoire. Le SKU est unique et les prix/quantites negatives sont refuses.

Un produit peut etre:

- `stockable`: gere le stock et les mouvements.
- `service`: sans stock, jamais bloque par les controles de quantite.

Le formulaire propose une aide de prix par marge `135%`, `145%`, `155%` ou manuel. Le prix de vente HT reste editable.

### 2. Gestion Stock

Page:

```text
/stock
```

L'utilisateur choisit un produit et ajoute une quantite.

L'application:

1. Augmente `products.stock_qty`.
2. Cree un mouvement `in` dans `stock_movements`.

Chaque entree de stock peut etre liee a un fournisseur et a un prix d'achat reel. Les sorties de stock sont faites uniquement au moment de la facturation, dans la meme transaction que la facture.

### 3. Operation Garage

Page:

```text
/operations
```

L'utilisateur cree un devis avec:

- client existant,
- vehicule existant,
- mode de paiement: `ESP` especes, `CHQ` cheque, `CB` carte/TPE, `VIR` virement,
- numero de cheque optionnel si le mode est `CHQ`,
- lignes dynamiques: produit stockable, produit service ou ligne libre,
- quantite, prix unitaire HT, remise `%`.

L'application calcule toujours cote serveur:

- `subtotal_ht`,
- `vat_rate`,
- `vat_amount`,
- `total_ttc`.

Cycle:

1. Devis `quote`: numero `DEV-YYYYMMDD-0001`, aucun mouvement de stock.
2. Bon de commande `order`: numero `BC-YYYYMMDD-0001`, cree depuis le devis, aucun mouvement de stock.
3. Facture `invoice`: numero `FAC-YYYYMMDD-0001`, verification du stock, sortie de stock et mouvements `out` dans la meme transaction.

Un devis peut etre facture directement: l'application cree la commande intermediaire puis la facture. Si le stock est insuffisant au moment de facturer, rien n'est ecrit.

### 4. Factures et Receipts

Page:

```text
/billing
```

Liste les derniers documents et donne deux actions:

- ouvrir le document imprimable: devis, bon de commande ou facture,
- ouvrir le receipt.

La facture imprimee reste en francais LTR et affiche le bloc `TOTAL HT / TVA / MT TTC A PAYER`, plus le montant TTC en lettres.

## Templates

### `templates/base.html.twig`

Layout HTML principal:

- `lang="ar"`
- `dir="rtl"`
- charge Google Font Cairo,
- charge `public/styles/app.css`,
- charge `public/scripts/app.js`.

### `templates/auth/login.html.twig`

Page de connexion minimaliste:

- fond noir,
- logo centre,
- email,
- mot de passe,
- bouton de connexion.

La page ne montre pas les comptes de test et ne contient pas de texte marketing.

### `templates/app/_topbar.html.twig`

Barre de navigation commune:

- logo SIM Auto centre dans la barre,
- lien dashboard toujours visible,
- menus deroulants par groupes: stock, vehicules, clients, operations, administration,
- administration affiche l'import pour admin et manager,
- administration affiche les utilisateurs pour admin seulement,
- fermeture des menus au clic exterieur et avec la touche Escape via JS vanilla,
- nom utilisateur, changement de mot de passe et logout.

### `templates/app/index.html.twig`

Dashboard principal.

Affiche quatre cartes:

- produits,
- stock,
- operations,
- factures et receipts.

Chaque carte ouvre une page dediee.

### `templates/app/products.html.twig`

Page produits:

- formulaire de creation de produit,
- filtres par recherche, categorie et etat stock,
- tableau des produits existants,
- liens detail, modification et desactivation.

### `templates/app/product_show.html.twig`

Page detail produit:

- informations produit,
- stock courant,
- historique des mouvements.

### `templates/app/product_edit.html.twig`

Page modification produit:

- SKU,
- nom,
- categorie,
- seuil minimum,
- prix achat,
- prix vente.

### `templates/app/stock.html.twig`

Page stock:

- formulaire d'entree de stock,
- fournisseur optionnel,
- prix d'achat optionnel,
- tableau des produits avec stock actuel et seuil minimum.

### `templates/app/operations.html.twig`

Page operations:

- choix client et vehicule,
- choix des pieces,
- saisie des services,
- creation de l'operation.

### `templates/app/billing.html.twig`

Page facturation:

- liste des operations,
- lien facture,
- lien receipt.

### `templates/app/users.html.twig`

Page reservee admin.

Affiche les utilisateurs avec:

- nom,
- email,
- role,
- statut actif/inactif,
- date de creation,
- badge role,
- badge statut,
- mention "vous" sur l'admin connecte,
- liens modifier, reinitialiser mot de passe, activer/desactiver.

### `templates/app/user_form.html.twig`

Formulaire admin pour:

- creer un utilisateur avec mot de passe et confirmation,
- modifier nom, email, role et statut.

### `templates/app/user_password.html.twig`

Formulaire utilise pour:

- reinitialisation du mot de passe par l'admin,
- changement de son propre mot de passe avec ancien mot de passe requis.

### `templates/app/categories.html.twig`

Page reservee admin pour creer, modifier et desactiver les categories.

### `templates/app/suppliers.html.twig`

Page fournisseurs:

- creation fournisseur,
- liste fournisseurs,
- modification,
- desactivation.

### `templates/app/clients.html.twig`

Page clients:

- particuliers,
- societes,
- champs ICE/IF/RC affiches seulement pour les societes.

### `templates/app/vehicles.html.twig`

Page vehicules:

- client,
- immatriculation,
- marque,
- modele,
- annee,
- kilometrage,
- notes.

### `templates/app/vehicle_settings.html.twig`

Page admin pour ajouter marques et modeles de vehicules.

### `templates/documents/invoice.html.twig`

Facture imprimable inspiree du modele SIM Auto fourni:

- logo officiel `public/images/logo-invoice.png`,
- en-tete conforme au bon papier: logo a gauche, services a droite,
- titre `FACTURE N° {invoice_no}`,
- document en francais LTR,
- bloc client a gauche,
- bloc vehicule a droite,
- mode de paiement en clair: `ESP`, `CHEQUE`, `CB` ou `VIR`,
- mention `Cheque N°` quand un numero de cheque est renseigne,
- tableau designation / quantite / prix / montant,
- total,
- net a payer,
- merci pour votre visite,
- footer avec informations de contact centralisees dans `App\Service\CompanyProfile`,
- filigrane transparent derriere le contenu.

### `templates/documents/receipt.html.twig`

Ticket receipt format petite imprimante:

- largeur proche 80 mm a l'impression,
- logo,
- numero receipt,
- date,
- client,
- vehicule,
- lignes,
- total,
- mode de paiement.
- numero de cheque quand renseigne.

## Assets Publics

### `public/images/logo-light.png`

Logo SIM Auto utilise dans:

- login,
- topbar,
- facture,
- receipt.

### `public/images/filigrane.png`

Image de fond filigrane utilisee:

- en arriere-plan du dashboard,
- en filigrane dans la facture.

### `public/images/brand-card.png`

Asset visuel conserve dans le projet. Il n'est plus central dans le login minimal actuel.

### `public/styles/app.css`

Contient tout le style de l'application:

- fond global avec filigrane transparent,
- login noir minimal,
- topbar,
- dashboard quatre cartes,
- formulaires,
- tableaux,
- facture A4,
- receipt 80 mm,
- responsive mobile,
- styles d'impression.

La charte visuelle utilise:

- noir,
- jaune SIM Auto,
- rouge accent,
- fond clair sobre.

### `public/scripts/app.js`

Script frontend leger. Il reste disponible pour les interactions UI simples. La version actuelle de l'application fonctionne principalement sans JavaScript.

Il gere aussi:

- les dropdowns de la navbar,
- la fermeture au clic exterieur,
- la fermeture clavier avec Escape,
- l'affichage conditionnel des champs societe pour les clients.
- l'affichage conditionnel du champ numero de cheque quand le paiement est `CHQ`.

## Vues Detail

Les principales entites ont une fiche lecture seule accessible aux roles connectes:

- `/clients/{id}`: informations completes du client, voitures du client, bouton ajout voiture preselectionne via `/vehicles?client={id}`, dernieres operations avec liens facture.
- `/suppliers/{id}`: informations fournisseur et dernieres entrees de stock liees.
- `/vehicles/{id}`: plaque, marque, modele, annee, kilometrage, notes, client proprietaire et historique operations.
- `/products/{id}`: informations produit et mouvements stock.
- `/categories/{id}`: categorie et produits lies.
- `/vehicle-brands/{id}` et `/vehicle-models/{id}`: fiches marques/modeles.

Le partiel `templates/app/_show_info.html.twig` fournit la grille libelle/valeur commune.

## Import en Masse

Le module d'import est accessible depuis:

```text
/import
```

Routes:

- `GET /import`: hub d'import.
- `GET /import/{entity}/template`: telecharge un modele CSV UTF-8 avec BOM, separateur `;`.
- `POST /import/{entity}`: importe le CSV rempli.

Entites supportees et colonnes:

- Clients: `type`, `name`, `surname`, `phone`, `email`, `address`, `ice`, `vat`, `rc`.
- Fournisseurs: `name`, `phone`, `email`, `address`, `ice`.
- Marques: `name`.
- Modeles: `brand_name`, `name`.
- Vehicules: `client_phone_ou_email`, `plate`, `brand_name`, `model_name`, `year`, `mileage`, `notes`.
- Produits: `sku`, `name`, `category_name`, `stock_qty`, `min_qty`, `purchase_price`, `sale_price`.

Regles d'import:

- resolution categorie/marque/modele par nom, insensible a la casse et aux espaces;
- creation automatique des categories, marques et modeles absents;
- produit existant par SKU: mise a jour des informations sans ecraser `stock_qty`;
- produit nouveau avec stock initial: creation du mouvement `in`;
- client existant par telephone ou email: mise a jour;
- lignes invalides ignorees avec rapport ligne par ligne;
- headers invalides: rejet total avant toute ecriture;
- limite: 2000 lignes par fichier;
- CSRF obligatoire sur les POST d'import.

Service:

```text
src/Service/ImportService.php
```

Templates:

```text
templates/app/import.html.twig
templates/app/import_result.html.twig
```

## Matrice de Droits

Admin:

- acces total;
- utilisateurs;
- imports;
- creation, modification et desactivation des produits, categories, fournisseurs;
- clients, vehicules, marques, modeles;
- operations, factures et receipts.

Manager:

- dashboard;
- produits, categories, fournisseurs;
- clients;
- vehicules, marques, modeles;
- imports;
- operations, factures et receipts;
- changement de son mot de passe.

Restrictions manager:

- pas d'acces au module utilisateurs `/users/...`;
- pas de desactivation/suppression d'enregistrements;
- les boutons interdits sont masques dans Twig avec `can(...)`;
- les actions interdites sont aussi verifiees cote serveur avec `App\Service\AccessControl`.

## Mode Impression

Le CSS contient un bloc `@media print`.

Pour la facture:

- largeur A4: `210mm`,
- suppression de la topbar et des boutons d'action,
- conservation du filigrane.

Pour le receipt:

- largeur: `80mm`,
- suppression des ombres,
- format compatible petite imprimante ticket.

## Donnees et Sauvegarde

Le fichier critique a sauvegarder est:

```text
data/simauto.sqlite
```

Il contient:

- utilisateurs,
- categories,
- fournisseurs,
- clients,
- vehicules,
- produits,
- mouvements stock,
- operations,
- lignes de facture.

## Tests

La suite de tests utilise Symfony PHPUnit Bridge.

Commande:

```powershell
docker-compose exec -T php php bin/phpunit
```

Tests couverts:

- creation produit et entree de stock,
- creation client societe et vehicule,
- operation garage avec sortie automatique de stock,
- donnees facture/receipt enrichies avec client et vehicule normalises,
- titre facture `FACTURE N°` avec numero de facture,
- paiement cheque stocke et imprime avec numero de cheque,
- fiche client avec vehicules lies et etat vide,
- fiche vehicule avec proprietaire et operations liees,
- migration idempotente de `operations.check_number`,
- refus SKU duplique,
- refus stock insuffisant,
- rollback transactionnel si la creation operation echoue,
- migration d'une ancienne base sans perte des donnees metier,
- creation utilisateur valide,
- validations email duplique, mot de passe court, confirmation differente,
- reinitialisation de mot de passe par admin,
- changement de mot de passe personnel avec ancien mot de passe incorrect,
- activation/desactivation utilisateur,
- garde-fous dernier admin actif,
- rejet CSRF simple sur les POST utilisateurs,
- import clients et re-import sans doublons,
- import produits avec creation categorie et mouvement stock initial,
- import produits existants sans ecrasement du stock,
- rejet headers CSV invalides,
- erreurs de ligne import sans annuler les lignes valides,
- resolution marque/modele insensible a la casse,
- matrice de droits admin/manager.

## Securite Utilisateurs

Le module utilisateurs garde l'architecture simple de l'application:

- pas de Doctrine,
- pas de composant Security Symfony,
- sessions natives Symfony,
- verification manuelle via `requireUser()` et `requireAdmin()`,
- toutes les routes `/users/...` sont reservees au role `admin`,
- `/profile/password` est accessible aux roles `admin` et `manager`,
- tous les POST du module utilisateurs utilisent un token CSRF stocke en session,
- aucun mot de passe n'est affiche dans les templates.

Garde-fous:

- l'admin connecte ne peut pas se desactiver lui-meme,
- le dernier admin actif ne peut pas etre transforme en manager,
- le dernier admin actif ne peut pas etre desactive,
- un manager forcant une URL `/users/...` est redirige avec un message de refus.

Pour remettre l'application a zero, arreter les conteneurs puis supprimer:

```text
data/simauto.sqlite
```

Au prochain acces, la base sera recreree avec les donnees initiales.

## Points a Prevoir Avant Production Reelle

1. Changer les mots de passe initiaux au premier login.
2. Ajouter les exports PDF si necessaire.
3. Ajouter une sauvegarde automatique de `data/simauto.sqlite`.
4. Ajouter HTTPS si deploye sur un serveur accessible publiquement.
5. Remplacer `chmod -R 777` par une gestion de droits plus stricte selon le serveur cible.

## Commandes Utiles

Demarrer:

```powershell
docker-compose up -d --force-recreate
```

Arreter:

```powershell
docker-compose down
```

Voir les logs:

```powershell
docker-compose logs -f
```

Vider le cache Symfony:

```powershell
docker-compose exec -T php php bin/console cache:clear
```

Lancer les tests:

```powershell
docker-compose exec -T php php bin/phpunit
```

Verifier les conteneurs:

```powershell
docker-compose ps
```

## Etat Actuel

L'application est fonctionnelle avec:

- login,
- session,
- roles admin et manager,
- gestion complete des utilisateurs,
- navbar avec menus deroulants,
- facture conforme au bon papier SIM Auto,
- titre facture `FACTURE N°`,
- paiement cheque avec numero optionnel,
- vues detail clients, fournisseurs, vehicules, produits, categories, marques et modeles,
- fiche client avec vehicules lies et dernieres operations,
- import CSV en masse,
- ACL central admin/manager,
- changement de mot de passe admin et personnel,
- limitation simple des tentatives login,
- CSRF simple sur le module utilisateurs,
- dashboard simple,
- pages dediees,
- stockage SQLite,
- CRUD categories,
- CRUD fournisseurs,
- CRUD clients,
- gestion marques/modeles/vehicules,
- ajout produit,
- detail/modification/desactivation produit,
- filtres produits,
- entree stock,
- operation garage,
- transaction stock/operation,
- generation facture,
- generation receipt,
- affichage utilisateurs admin,
- tests PHPUnit.
