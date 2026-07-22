# SIM Auto Workshop - Documentation Projet

## Objectif

Cette application est une petite application Symfony pour la gestion interne de SIM Auto.
Elle n'est pas concue comme un site vitrine. C'est une application metier simple, bilingue arabe/francais, centree sur quatre piliers:

1. Entree des produits dans le stock.
2. Gestion et suivi du stock.
3. Creation des operations de garage: reparations, services, pieces.
4. Cycle devis -> bon de commande -> facture, avec TVA, reporting financier et impression des receipts.

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

- `admin`: acces total sans exception: lecture, creation, modification, suppression, activation/desactivation, imports, rapports, parametres et utilisateurs.
- `manager`: lecture des vues metier + creation uniquement pour le travail quotidien: produits/clients/fournisseurs/vehicules, entrees de stock, devis, progression devis -> commande -> facture, impression documents/receipts et changement de son mot de passe. Il ne modifie, ne supprime, ne desactive et n'importe pas les enregistrements existants.

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
- `GET /operations/history`: historique filtrable des devis, bons de commande et factures.
- `GET /operations/{id}`: fiche detail lecture seule d'une operation avec lignes, totaux et chaine documentaire.
- `GET|POST /operations/{id}/edit`: modification admin d'un devis brouillon uniquement.
- `GET /billing`: page des factures et receipts.
- `GET /reports/finance`: situation financiere jour, semaine, mois ou periode libre.
- `GET /reports/finance/operation/{id}`: detail de marge d'une facture.
- `GET /reports/finance/day-receipt`: ticket de cloture journalier 80 mm.
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

- `GET /document/{id}`: affiche le document imprimable universel selon son type: devis, bon de commande ou facture.
- `GET /invoice/{id}`: alias historique de `/document/{id}` conserve pour compatibilite.
- `GET /receipt/{id}`: affiche un ticket receipt imprimable, uniquement pour les factures.

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
- `ref_universal`: reference constructeur/OEM universelle, optionnelle.
- `ref_company`: reference interne SIM Auto, optionnelle et unique quand renseignee.
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

## Reporting Financier

Le module de situation financiere est accessible depuis:

```text
/reports/finance
```

Il est reserve au role `admin` via la capacite:

```text
reports.view
```

Routes:

- `GET /reports/finance`: tableau de bord financier.
- `GET /reports/finance/operation/{id}`: detail des marges d'une facture.
- `GET /reports/finance/day-receipt?date=Y-m-d`: ticket de cloture de caisse.

Filtres disponibles:

- `today`: jour courant.
- `week`: semaine courante du lundi au dimanche.
- `month`: mois courant.
- `custom`: periode libre avec `from` et `to` au format `Y-m-d`.

Si les dates custom sont invalides ou inversees, l'application revient au jour courant et affiche un avertissement.

Le reporting ne compte que les operations:

```sql
doc_type = 'invoice'
```

Les devis et bons de commande sont exclus du chiffre d'affaires et de la marge.

### Regles de marge

Tous les prix stockes sont TTC. Le reporting travaille avec le HT extrait depuis le TTC et le taux TVA de l'operation.

Pour chaque ligne de facture:

- Produit stockable lie a un produit existant:
  - `cout_ht = (purchase_price / (1 + vat_rate / 100)) * quantity`
  - `marge = total_ht - cout_ht`
- Service ou produit de type `service`:
  - `cout_ht = 0`
  - `marge = total_ht`
- Ligne libre ou produit supprime:
  - `cout_ht = 0`
  - `marge = total_ht`
  - ligne marquee comme estimee.

Le cout utilise le `purchase_price` actuel du produit. Il n'y a pas d'historique de cout dans cette iteration.

Le taux de marge est:

```text
marge / subtotal_ht * 100
```

avec protection contre la division par zero.

Le ticket de cloture journalier est un document imprime en francais LTR, format 80 mm, avec:

- date de la journee,
- horodatage d'impression,
- utilisateur,
- nombre de factures,
- total TTC,
- total HT,
- TVA,
- ventilation par mode de paiement,
- total marge,
- liste compacte des factures.

## Flux Metier

## Internationalisation

L'interface utilise `symfony/translation`.

- Fichiers: `translations/messages.ar.yaml` et `translations/messages.fr.yaml`.
- Locale par defaut: `ar`.
- Selecteur `AR | FR` visible directement dans la topbar.
- Le choix est conserve en session et dans le cookie `simauto_locale`.
- `templates/base.html.twig` adapte `lang` et `dir`: arabe en RTL, francais en LTR.
- La topbar inverse naturellement l'ordre de lecture selon la langue.
- Les titres, boutons, labels, tableaux, messages vides, alertes connues et pages metier utilisent les cles `|trans`.
- Les donnees saisies par l'utilisateur, par exemple les noms de categories ou produits, restent dans leur langue d'origine.
- Les documents imprimes restent en francais LTR.

### 1. Entree Produit

Page:

```text
/products
```

L'utilisateur saisit:

- code produit,
- reference universelle constructeur/OEM,
- reference interne SIM Auto,
- nom,
- categorie,
- quantite initiale,
- seuil minimum,
- prix d'achat,
- prix de vente.

L'application:

1. Cree le produit dans `products`.
2. Cree un mouvement d'entree dans `stock_movements`.

Le produit est rattache a une categorie obligatoire. Le SKU est le code produit historique et reste unique. `ref_universal` identifie la reference constructeur/OEM. `ref_company` identifie la reference interne SIM Auto; elle est unique quand elle est renseignee, mais plusieurs produits peuvent rester sans reference interne.

La recherche produit donne priorite aux references:

1. correspondance exacte `ref_company`, puis `ref_universal`, puis `sku`;
2. correspondance prefixe sur les trois memes champs;
3. correspondance partielle sur le nom produit;
4. correspondance partielle sur le nom categorie.

Les espaces superflus et la casse sont ignores. Les filtres categorie, etat stock et actif/inactif restent combinables.

Un produit peut etre:

- `stockable`: gere le stock et les mouvements.
- `service`: sans stock, jamais bloque par les controles de quantite.

Le formulaire propose une aide de prix par marge affichee `35%`, `45%`, `55%` ou manuel. En interne, les valeurs envoyees restent `135`, `145`, `155` pour conserver le calcul existant: prix de vente = prix d'achat x 1.35, 1.45 ou 1.55. Le prix de vente reste editable.

La saisie d'operation reprend la meme convention par ligne de produit:

- le select de marge affiche `35%`, `45%`, `55%` ou manuel;
- si un produit stockable possede un `purchase_price`, choisir une marge recalcule le prix unitaire TTC de la ligne (`purchase_price x coefficient`);
- si le prix est modifie a la main, la ligne repasse en manuel;
- les services, lignes libres et produits sans prix d'achat restent en prix manuel;
- le serveur recalcule toujours le prix final depuis le produit et le mode de marge poste, le JavaScript ne sert qu'a l'apercu.
- un devis brouillon peut etre modifie par l'admin avec les memes regles; les managers ne modifient pas un document existant.

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
- quantite, prix unitaire TTC, remise `%`.

L'application calcule toujours cote serveur:

- `subtotal_ht`,
- `vat_rate`,
- `vat_amount`,
- `total_ttc`.

Important: la TVA est incluse dans les prix saisis. L'application n'ajoute donc pas la TVA au total. Elle extrait le HT et la TVA depuis le TTC.

Exemple avec TVA 20%:

- prix saisi / total TTC: `2000.00 DH`,
- montant HT: `1666.67 DH`,
- TVA: `333.33 DH`,
- total a payer: `2000.00 DH`.

Cycle:

1. Devis `quote`: numero `DEV/YYYYMM/1`, aucun mouvement de stock.
2. Bon de commande `order`: numero `BC/YYYYMM/1`, cree depuis le devis, aucun mouvement de stock.
3. Facture `invoice`: numero `INV/YYYYMM/1`, verification du stock, sortie de stock et mouvements `out` dans la meme transaction.

Un devis peut etre facture directement: l'application cree la commande intermediaire puis la facture. Si le stock est insuffisant au moment de facturer, rien n'est ecrit.

### 4. Factures et Receipts

Page:

```text
/billing
```

Liste les derniers documents et donne les actions:

- afficher la fiche detail de l'operation,
- ouvrir le document imprimable: devis, bon de commande ou facture,
- ouvrir le receipt uniquement pour une facture.

Le document imprime reste en francais LTR et utilise le meme gabarit A4 pour les trois types:

- devis: `DEVIS N° {quote_no}` avec mention `Devis valable 30 jours`;
- bon de commande: `BON DE COMMANDE N° {order_no}`;
- facture: `FACTURE N° {invoice_no}`.

Les montants saisis et visibles dans l'interface sont des TTC directs. L'ecran operation, la fiche detail operation, l'historique, la page facturation et le receipt n'affichent pas de decomposition TVA. Les champs `subtotal_ht`, `vat_rate`, `vat_amount` et `total_ttc` restent stockes pour la facture et le reporting.

Regle d'impression:

- devis: lignes TTC, total TTC, `NET A PAYER`, montant en lettres, sans `MT HT` ni `TVA`;
- bon de commande: lignes TTC, total TTC, `NET A PAYER`, montant en lettres, sans `MT HT` ni `TVA`;
- facture: seul document client avec `MT HT`, `TVA`, `MT TTC A PAYER` et montant TTC en lettres;
- receipt: ticket compact en TTC direct, sans decomposition TVA.

### 5. Historique Operations

Page:

```text
/operations/history
```

L'historique affiche tous les documents `quote`, `order` et `invoice` avec un badge distinctif.

Filtres serveur:

- recherche texte sur numero de devis, bon de commande, facture, client ou immatriculation;
- type de document: devis, bon de commande, facture ou tous;
- dates inclusives `from` et `to` sur la date de creation;
- mode de paiement: `ESP`, `CHQ`, `CB`, `VIR`.

Les dates invalides ou inversees sont ignorees avec un avertissement, sans planter la page. La liste est triee par date descendante et limitee a 200 lignes, avec compteur total.

### 6. Detail Operation

Page:

```text
/operations/{id}
```

La fiche detail affiche:

- type de document, numero, date, statut, client, vehicule, paiement et numero de cheque;
- lignes: designation, type, quantite, prix unitaire TTC, remise, total HT;
- totaux: `MT HT`, `TVA`, `MT TTC`;
- liens client et vehicule;
- chaine documentaire via `parent_id`: devis, bon de commande et facture lies;
- boutons imprimer, receipt pour facture seulement, et retour vers l'historique.

## Templates

### `templates/base.html.twig`

Layout HTML principal:

- `lang` dynamique selon la locale courante,
- `dir` dynamique: `rtl` en arabe, `ltr` en francais,
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

- logo SIM Auto dans la zone marque de la barre,
- lien dashboard toujours visible,
- menus deroulants par groupes: stock, vehicules, clients, operations, administration,
- le menu administration est reserve a l'admin,
- les vues marques/modeles restent visibles via le groupe vehicules pour admin et manager,
- selecteur langue `AR | FR` visible dans la topbar,
- profil utilisateur en menu dedie avec changement de mot de passe et logout,
- fermeture des menus au clic exterieur et avec la touche Escape via JS vanilla,
- responsive mobile avec bouton hamburger.

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
- lignes dynamiques produit/service/ligne libre,
- prix unitaires TTC,
- extraction HT/TVA/TTC dans la preview,
- creation du devis,
- lien vers l'historique des operations.

### `templates/app/operations_history.html.twig`

Historique filtrable des documents:

- barre de recherche,
- filtres type, dates et paiement,
- tableau avec badges de type,
- actions afficher, imprimer et receipt pour factures.

### `templates/app/operation_show.html.twig`

Fiche detail lecture seule d'une operation:

- informations principales,
- lignes,
- totaux,
- liens client/vehicule,
- chaine documentaire devis -> bon de commande -> facture.

### `templates/app/billing.html.twig`

Page facturation:

- liste des operations,
- lien facture,
- lien receipt.

### `templates/app/report_finance.html.twig`

Page situation financiere:

- filtres jour, semaine, mois et periode libre,
- cartes KPI: CA TTC, total HT, TVA, marge, taux de marge, nombre de factures,
- ventilation par mode de paiement,
- tableau des factures de la periode,
- lien vers le detail marge de chaque facture,
- bouton ticket de cloture quand la periode correspond a un seul jour.

### `templates/app/report_finance_operation.html.twig`

Detail marge d'une facture:

- informations facture,
- lignes avec type, quantite, PU TTC, total HT, cout HT, marge et taux,
- badge estime pour les lignes libres ou produits introuvables,
- totaux de facture.

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

Page document imprimable inspiree du modele SIM Auto fourni. Elle utilise le partiel commun:

```text
templates/documents/_operation_doc.html.twig
```

Le partiel rend le document A4 pour:

- logo officiel `public/images/logo-invoice.png`,
- en-tete conforme au bon papier: logo a gauche, services a droite,
- titre dynamique `DEVIS N°`, `BON DE COMMANDE N°` ou `FACTURE N°`,
- document en francais LTR,
- bloc client a gauche,
- bloc vehicule a droite,
- mode de paiement en clair: `ESP`, `CHEQUE`, `CB` ou `VIR`,
- mention `Cheque N°` quand un numero de cheque est renseigne,
- tableau designation / quantite / prix / montant,
- lignes en montant HT calcule depuis les prix TTC,
- bloc `MT HT`, `TVA`, `MT TTC A PAYER`,
- merci pour votre visite,
- footer avec informations de contact centralisees dans `App\Service\CompanyProfile`,
- filigrane transparent derriere le contenu.

La facture garde le meme rendu visuel que l'ancien template; seul le HTML commun a ete factorise pour eviter la duplication entre devis, commande et facture.

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

### `templates/documents/day_receipt.html.twig`

Ticket de cloture de caisse:

- document francais LTR,
- format petite imprimante 80 mm,
- logo,
- date, horodatage, utilisateur,
- nombre de factures,
- total general TTC en grand,
- ventilation paiement,
- liste compacte des factures.

Le ticket imprime ne montre pas HT, TVA ni marge. Ces indicateurs restent disponibles dans `/reports/finance`.

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
- la preview des operations avec prix TTC, extraction HT et TVA incluse.
- l'affichage du formulaire dates quand le preset reporting est `custom`.

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
- produits CSV avec colonnes `sku`, `ref_universal`, `ref_company`, `name`, `category_name`, `stock_qty`, `min_qty`, `purchase_price`, `sale_price`;
- produit existant par SKU: mise a jour des informations et references sans ecraser `stock_qty`;
- `ref_company` dupliquee dans le fichier ou deja utilisee par un autre produit: ligne rejetee;
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

## Composant Select avec Recherche

`public/scripts/app.js` enrichit progressivement les `<select data-combobox>` en combobox vanilla:

- le `<select>` natif reste dans le HTML et continue a porter la valeur POST;
- sans JavaScript, le formulaire reste utilisable avec le select classique;
- recherche locale sur les options chargees;
- navigation clavier fleches, Entree et Echappement;
- fermeture au clic exterieur;
- compatibilite RTL et LTR.

Le composant est utilise pour les choix de clients, vehicules, produits, fournisseurs, categories, marques et modeles. Les listes longues sont filtrees cote client dans cette iteration; il n'y a pas encore d'appel AJAX, donc la recherche live couvre seulement les options chargees dans la page.

Les listes produits, clients, fournisseurs et vehicules disposent aussi d'une recherche live cote client sur les lignes deja affichees. La recherche serveur reste la reference pour couvrir toute la base.

## Matrice de Droits

Admin:

- acces total;
- utilisateurs;
- imports;
- rapports financiers;
- creation, modification et desactivation des produits, categories, fournisseurs;
- clients, vehicules, marques, modeles;
- operations, factures et receipts.

Manager:

- lecture de toutes les listes et fiches detail metier;
- lecture des parametres visibles: categories, marques et modeles;
- creation de produits, clients, fournisseurs et vehicules;
- creation d'entrees de stock;
- creation de devis et progression du workflow devis -> bon de commande -> facture;
- impression documents et receipts;
- changement de son mot de passe.

Restrictions manager:

- pas de modification d'enregistrements existants (`/edit` et POST associes);
- pas de suppression;
- pas d'activation/desactivation;
- pas d'import en masse;
- pas de rapports financiers;
- pas de creation/modification/suppression des parametres: categories, marques et modeles;
- pas d'acces au module utilisateurs `/users/...`;
- pas de modification d'un devis brouillon deja cree; l'admin seul peut editer ou supprimer un devis brouillon;
- aucun role ne peut modifier ou supprimer une commande confirmee ou une facture;
- les boutons interdits sont masques dans Twig avec `can(...)`;
- les actions interdites sont aussi verifiees cote serveur avec `App\Service\AccessControl`.

Permissions centralisees:

- admin: `can(...)` retourne vrai pour toutes les permissions;
- manager autorise en lecture: `view`, `view.dashboard`, `view.products`, `view.stock`, `view.categories`, `view.suppliers`, `view.clients`, `view.vehicles`, `view.vehicle_settings`, `view.operations`, `view.billing`, `view.documents`;
- manager autorise en action quotidienne: `create`, `progress_document`;
- manager interdit: `edit`, `delete`, `toggle`, `import`, `imports`, `manage_users`, `reports.view`.

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

En version desktop Windows, la base n'est pas stockee dans le dossier d'installation. Elle est creee ici:

```text
%APPDATA%\SIMAutoWorkshop\data\simauto.sqlite
```

Le cache et les logs Symfony sont egalement deplaces hors du dossier applicatif:

```text
%APPDATA%\SIMAutoWorkshop\var\
```

Ce deplacement est pilote par la variable d'environnement `SIMAUTO_DATA_DIR`. Sans cette variable, l'application garde le comportement web/Docker classique et utilise le dossier local `data/`.

La version desktop cree une sauvegarde automatique au demarrage:

```text
%APPDATA%\SIMAutoWorkshop\backups\simauto-YYYYMMDD-HHMMSS.sqlite
```

Les 30 dernieres sauvegardes sont conservees. Une page admin `Sauvegardes` permet de voir les fichiers et d'ouvrir le dossier dans l'explorateur Windows.

## Version Desktop Windows 11

Une version installable Windows 11 est preparee sans Docker cote client.

Choix technique:

- coque PowerShell + WebView2/Edge app mode, pour eviter Tauri/Rust et garder un packaging simple,
- PHP 8.3 NTS x64 portable integre au package,
- serveur PHP built-in lie uniquement a `127.0.0.1`,
- port dynamique a partir de `8090` si le port est occupe,
- healthcheck `/login` avec timeout 15 secondes,
- instance unique par mutex Windows,
- arret propre du processus PHP a la fermeture.

Fichiers importants:

```text
desktop/Start-SIMAutoWorkshop.ps1
desktop/php/php.ini
desktop/SIMAutoWorkshop.cmd
desktop/SIMAutoWorkshopPortable.cmd
build-desktop.ps1
installer/simauto-workshop.iss
DESKTOP_WINDOWS_CHECKLIST.txt
```

Build desktop depuis un poste de build Windows:

```powershell
powershell -ExecutionPolicy Bypass -File .\build-desktop.ps1 -PhpZipUrl "URL_PHP_8_3_NTS_X64.zip" -PhpSha256 "SHA256_OFFICIEL"
```

Le script:

- assemble l'application dans `build/desktop/app`,
- verifie le hash SHA256 de PHP,
- installe Composer en mode production (`--no-dev --optimize-autoloader`),
- cree `dist/SIMAutoWorkshop-portable.zip`,
- compile `dist/SIMAutoWorkshop-Setup.exe` si Inno Setup (`iscc.exe`) est disponible.

La version portable stocke ses donnees dans le dossier portable:

```text
data/
var/
backups/
```

Important: la version desktop est volontairement locale (`127.0.0.1`) et n'est pas destinee a etre exposee aux autres PC. Pour un acces multi-postes sur reseau local, utiliser la version serveur/Docker de production decrite dans `INSTALL_CLIENT_PROD.txt`.

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
- TVA incluse: le TTC reste le montant saisi, HT et TVA sont extraits du TTC,
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
- matrice de droits admin/manager,
- calcul marge produit stockable avec cout achat HT extrait du TTC,
- marge service a 100% du total HT,
- ligne libre marquee estimee,
- synthese financiere sur factures uniquement,
- periode custom inclusive,
- fallback sur aujourd'hui en cas de dates inversees,
- ventilation par mode de paiement,
- protection division par zero du taux de marge,
- acces reporting reserve admin,
- rendu ticket de cloture,
- rendu document devis/commande/facture via le partiel commun,
- recherche historique par texte, type, paiement et dates inclusives,
- absence de receipt sur devis et bon de commande,
- fiche detail operation avec liens parent/enfant.

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
3. Ajouter HTTPS si deploye sur un serveur accessible publiquement.
4. Remplacer `chmod -R 777` par une gestion de droits plus stricte selon le serveur cible.

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
- interface bilingue AR/FR sur les ecrans applicatifs,
- facture conforme au bon papier SIM Auto,
- titre facture `FACTURE N°`,
- TVA incluse dans les prix avec extraction HT/TVA sur facture,
- references produits `ref_universal` et `ref_company`,
- recherche produit priorisee par references,
- combobox vanilla reutilisable sur les selects d'entites,
- recherche live cote client sur listes principales,
- situation financiere jour/semaine/mois/periode libre,
- historique des operations avec filtres,
- document imprimable universel `/document/{id}`,
- fiche detail operation `/operations/{id}`,
- calcul marge par facture et par ligne,
- ticket de cloture journalier 80 mm,
- ticket de cloture simplifie sans HT/TVA/marge imprimee,
- aide marge affichee 35%, 45%, 55% avec calcul interne conserve,
- paiement cheque avec numero optionnel,
- version desktop Windows 11 avec PHP portable,
- donnees desktop dans AppData via `SIMAUTO_DATA_DIR`,
- sauvegarde automatique au demarrage desktop,
- page admin des sauvegardes,
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
