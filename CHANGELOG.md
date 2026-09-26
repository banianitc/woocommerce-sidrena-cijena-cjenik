# Changelog

Sve značajne promjene projekta bilježe se u ovoj datoteci.

## [1.3.1] - 2026-09-26

### Dodano

- instalacijski ZIP paket za WordPress automatski se izrađuje, provjerava i prilaže svakom GitHub izdanju

## [1.3.0] - 2026-09-26

### Ispravljeno

- varijabilni proizvodi više ne dobivaju sidrenu cijenu na roditeljskom proizvodu; sidrene cijene ostaju po varijacijama (vrijednosti koje su ranije verzije spremile na roditelja nisu uklonjene)
- proizvodi bez cijene izvoze se s praznom maloprodajnom cijenom umjesto 0.00, pa ih provjera spremnosti ponovno prikazuje
- prazna sidrena cijena početno se popunjava redovnom cijenom (bez akcijskog sniženja) umjesto aktivne cijene; isto vrijedi za privremeni prikaz i izvoz
- neispravni UTF-8 znakovi više ne prazne vrijednosti, a kontrolni znakovi više ne kvare XML cjenik
- predmemorija raspona sidrenih cijena briše se nakon skupnog popunjavanja

### Poboljšano

- arhivska kopija cjenika sprema se samo kad se sadržaj promijeni, a najmanje jednom dnevno
- zaostale privremene datoteke prekinutog generiranja automatski se brišu
- javni download više ne izračunava hash cijele datoteke pri svakom zahtjevu

## [1.2.3] - 2026-09-21

### Refaktorirano

- interni PHP namespace promijenjen je u neutralni `SidrenaCijenaCjenik`

## [1.2.2] - 2026-09-21

### Ispravljeno

- brzo generiranje više ne prijavljuje lažni gubitak vlasništva nad lockom kada se obnova dogodi unutar iste sekunde

## [1.2.1] - 2026-09-21

### Refaktorirano

- konfiguracija sidrene cijene premještena je u namespaciranu `Config` klasu
- postojeći meta-ključevi, opcije i globalne konstante ostaju kompatibilni

## [1.2.0] - 2026-09-21

### Sigurnost i pouzdanost

- CSV tekstualne vrijednosti neutraliziraju formule pri otvaranju u tabličnim programima
- javni download više ne pokreće sinkrono generiranje cjenika
- svi generatori koriste zajednički atomski lock i verzionirano stanje promjena
- datoteke se objavljuju iz privremenih datoteka, uz povrat prethodne verzije pri pogrešci
- nedostupan WooCommerce više ne može zamijeniti ispravan cjenik praznim datotekama

### Poboljšano

- proizvodi se obrađuju u stranicama i CSV/XML se zapisuju strujno
- dnevno generiranje prati odabrano lokalno vrijeme i nakon promjene ljetnog računanja vremena
- readiness razlikuje spremljenu sidrenu cijenu od privremenog fallbacka
- dodana kompatibilnost s PHP-om 8.4 za CSV zapis
- release paket uključuje sve runtime module, licencu i obavijest o podrijetlu

## [1.1.5] - 2026-09-21

### Dodano

- javne stabilne adrese za CSV i XML cjenik
- odabir CSV/XML formata i kratki kodovi za poveznice
- ugrađena kartica Pomoć s dijagnostikom i rješavanjem problema
- početna sidrena cijena iz trenutačne aktivne WooCommerce cijene kada je polje prazno
- deklarirana kompatibilnost s HPOS-om te Cart i Checkout Blocks značajkama

### Poboljšano

- cjenik podržava arhivu, HTTP predmemoriranje i UTF-8 XML
- postojeće sidrene cijene ostaju netaknute pri inicijalizaciji
