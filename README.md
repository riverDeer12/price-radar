# Price Radar

Web aplikacija koja svaki dan u 10:00 preuzima najnoviju arhivu cijena s
[cijene.dev](https://cijene.dev/archive) (CSV podaci o cijenama hrvatskih
trgovačkih lanaca) i sprema ih u bazu, tako da uvijek postoji svježa slika
asortimana i cijena po lancu. Uvoz se može pokrenuti i ručno, klikom na gumb
na nadzornoj ploči.

Stack: Laravel + Livewire, PostgreSQL. Bez Node/Vite build koraka (Tailwind
se učitava preko CDN-a), zbog jednostavnijeg deploya na klasičan PHP-FPM +
nginx server.

## Kako radi uvoz

1. `App\Services\ArchiveImportService::latestArchive()` dohvaća
   `GET https://api.cijene.dev/v0/list` i uzima zadnji datum.
2. ZIP arhiva (~70-80 MB) preuzima se streamano na disk (`storage/app/archives`).
3. Arhiva se raspakira; za svaki trgovački lanac (podmapa u ZIP-u) parsiraju
   se `stores.csv`, `products.csv`, `prices.csv`.
4. Trgovine i proizvodi se upisuju/ažuriraju (`chain_id + store_code` /
   `chain_id + product_code` su jedinstveni ključevi, jer su šifre
   proizvoda/trgovina jedinstvene samo unutar lanca).
5. Retci cijena prvo idu u pomoćnu tablicu `price_import_staging`, a zatim se
   jednim SQL upitom:
   - upisuju promjene u `price_history` (samo redovi gdje se cijena ili
     akcijska cijena stvarno promijenila u odnosu na `prices`),
   - "upsertaju" u `prices` (trenutno stanje).
6. Proizvodi koji su nestali iz asortimana uspješno uvezenog lanca (nisu se
   pojavili u današnjem `prices.csv`) brišu se iz `prices` (ostaju u
   `products`/`price_history` radi povijesti).
7. Ako obrada pojedinog lanca padne, ostali lanci se svejedno uvezu — greška
   se bilježi po lancu unutar `archive_imports.stats`, a taj lanac zadržava
   jučerašnje cijene dok se ne popravi.

Svaki pokušaj uvoza (ručni i automatski) ostavlja trag u tablici
`archive_imports` (status, trajanje, statistika, poruka greške) — to se vidi
na nadzornoj ploči (`/`).

## Popis za kupovinu (`/shopping-list`)

Upišeš stavke (jedna po retku), odabereš grad, a `App\Services\ShoppingListService`:

1. Za svaku stavku nađe naziv proizvoda koji joj najbolje odgovara —
   PostgreSQL `pg_trgm` (`ILIKE` + `similarity()`, GIN indeks na
   `products.name`), s prednošću za nazive koji **počinju** tom riječi (npr.
   za "mlijeko" ne smije pobijediti "VRČ ZA MLIJEKO").
2. Skupi i sve gotovo identične varijante tog naziva kod drugih lanaca
   ("COCA COLA 2l" / "GAZ PIĆE COCA COLA 2L" / "SOK COCA COLA 2L PET…" —
   isti proizvod, svaki lanac ga drukčije zove) i preko pravog EAN barkoda
   (obični niz od 8-14 znamenki) poveže sve te zapise kao "isti proizvod"
   kroz lance. Kad proizvod nema pravi EAN (arhiva tada koristi
   `<lanac>:<sifra>`), usporedba ostaje samo unutar tog jednog lanca.
3. Za odabrani grad ispiše: najjeftiniju poslovnicu po stavci, i poslovnice
   rangirane po tome koliko stavki s popisa uopće imaju (pa po ukupnoj
   cijeni) — za kupovinu na jednom mjestu.

Poznato ograničenje: kratki/generički pojmovi (npr. samo "mlijeko") mogu
pogoditi manje poznat proizvod ako ima kraći/sličniji naziv — nema pojma o
"popularnosti" proizvoda, samo o sličnosti teksta.

## Skeniraj barkod (`/scan`)

Za slučaj kad artikl u trgovini nema istaknutu cijenu: skeniraj barkod
kamerom telefona (ili ga upiši ručno) i `App\Services\BarcodeLookupService`
prikaže **stvarni** EAN barkod pronađen u `products.barcode`, cijenu po
svakom lancu koji ga prodaje (najjeftiniji prvi), broj poslovnica i primjer
adrese — opcionalno suženo na jedan grad.

- Kamera koristi [html5-qrcode](https://github.com/mebjas/html5-qrcode)
  (učitano preko CDN-a, bez Node build koraka) s formatima EAN-13/EAN-8/
  UPC-A/UPC-E/Code128. **Zahtijeva HTTPS** (ili `localhost`) — na produkciji
  radi čim je nginx iza certbota (vidi Deployment ispod); na `http://` bez
  toga preglednik neće dati pristup kameri, pa je ručni unos uvijek dostupan
  kao fallback.
- Neki lanci koriste isti "placeholder" barkod (npr. `9999999999994`) za
  desetke potpuno nepovezanih proizvoda bez pravog EAN-a (rinfuzno voće,
  biljke). `App\Support\Barcode::isRealEan()` to prepoznaje (premalo
  različitih znamenki) i takve kodove tretira kao "nije pronađeno" umjesto
  da izmiješa nepovezane proizvode — koristi ga i ovaj servis i
  `ShoppingListService`.

## Baza podataka

- `chains`, `stores`, `products` — matični podaci po lancu.
- `prices` — **trenutno** stanje cijena (jedan redak po proizvodu i
  poslovnici).
- `price_history` — redak se dodaje samo kad se cijena/akcijska cijena
  promijeni (ne raste nepotrebno svaki dan).
- `price_import_staging` — pomoćna tablica, prazni se na početku/kraju svakog
  uvoza.
- `archive_imports` — povijest i status pokretanja uvoza.

## Lokalni razvoj

Preduvjeti: PHP 8.4+, Composer, PostgreSQL.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

U `.env` postavi `DB_*` na lokalnu Postgres bazu (baza mora već postojati,
npr. `createdb price_radar`) te `ADMIN_EMAIL`/`ADMIN_PASSWORD` (podaci za
jedini korisnički račun aplikacije), zatim:

```bash
php artisan migrate
php artisan db:seed
php artisan serve
```

Prijava je na `/login` s podacima iz `ADMIN_EMAIL`/`ADMIN_PASSWORD`. Za
promjenu lozinke: promijeni `ADMIN_PASSWORD` u `.env` i ponovno pokreni
`php artisan db:seed` (seeder je idempotentan — samo ažurira lozinku
postojećeg korisnika).

Ručno pokretanje uvoza (bez čekanja na queue worker):

```bash
php artisan archive:import --sync
```

Ili s pokretanjem preko reda čekanja (kao što radi i gumb na nadzornoj
ploči te dnevni raspored):

```bash
php artisan queue:work --once
php artisan archive:import
```

## Deployment na vlastiti server (nginx + PHP-FPM, bez Dockera)

1. **Kod i ovisnosti**

   ```bash
   git clone <repo> /var/www/price-radar
   cd /var/www/price-radar
   composer install --no-dev --optimize-autoloader
   cp .env.example .env
   php artisan key:generate
   ```

   Postavi u `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`,
   `DB_*` (PostgreSQL), `QUEUE_CONNECTION=database`,
   `SESSION_DRIVER=database`, te `ADMIN_EMAIL`/`ADMIN_PASSWORD` (jedini
   korisnički račun — postavi jaku lozinku, ovo je stvarna produkcija).

2. **Baza**

   ```bash
   php artisan migrate --force
   php artisan db:seed --force
   ```

3. **nginx** (primjer server bloka, prilagodi domenu/putanju/PHP-FPM socket):

   ```nginx
   server {
       listen 80;
       server_name price-radar.example.com;
       root /var/www/price-radar/public;

       index index.php;

       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }

       location ~ \.php$ {
           include snippets/fastcgi-php.conf;
           fastcgi_pass unix:/run/php/php8.4-fpm.sock;
       }

       location ~ /\.(?!well-known).* {
           deny all;
       }
   }
   ```

   Aplikacija ima svoj login (`/login`, jedan korisnik iz `ADMIN_EMAIL`/
   `ADMIN_PASSWORD`) pa dodatna zaštita na nginxu nije nužna, ali je svakako
   preporučeno staviti ovo iza HTTPS-a (npr. certbot).

4. **Queue worker** (obrađuje uvoz arhive u pozadini — nužan i za ručni gumb
   i za dnevni raspored). Preporučeno kao `systemd` servis:

   `/etc/systemd/system/price-radar-worker.service`:

   ```ini
   [Unit]
   Description=Price Radar queue worker
   After=network.target

   [Service]
   User=www-data
   WorkingDirectory=/var/www/price-radar
   ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=1 --max-time=3600
   Restart=always

   [Install]
   WantedBy=multi-user.target
   ```

   ```bash
   sudo systemctl enable --now price-radar-worker
   ```

5. **Scheduler** (pokreće `archive:import` svaki dan u 10:00 — vidi
   `routes/console.php`). Jedan cron unos pokreće Laravelov scheduler svake
   minute, a on interno odlučuje kad je vrijeme za stvarni posao:

   ```
   * * * * * cd /var/www/price-radar && php artisan schedule:run >> /dev/null 2>&1
   ```

6. **Deploy nove verzije koda**:

   ```bash
   git pull
   composer install --no-dev --optimize-autoloader
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   sudo systemctl restart price-radar-worker
   ```

## Napomene / moguća proširenja

- Prijava je namjerno minimalna (jedan račun, bez registracije/reset lozinke
  putem e-maila) — dovoljno za osobnu/internu upotrebu.
- Pretraga proizvoda (`/products`) koristi običan `ILIKE`; `pg_trgm` GIN
  indeks na `products.name` (uveden za popis za kupovinu) bi ubrzao i ovu
  pretragu ako zatreba.
- `config/cijene.php` sadrži mapiranje šifre lanca (naziv mape u arhivi) na
  čitljivo ime — ako cijene.dev doda novi lanac, aplikacija će ga
  automatski uvesti pod generiranim imenom dok se ne doda u mapiranje.
