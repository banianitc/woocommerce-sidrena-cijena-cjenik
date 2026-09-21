# Sidrena Cijena i Cjenik za WooCommerce

Besplatan WordPress dodatak za prikaz sidrene cijene te objavu javnog, strojno čitljivog CSV/XML cjenika u WooCommerce trgovini.

Dodatak je izrađen kao tehnička pomoć hrvatskim trgovcima u provedbi odluka objavljenih u Narodnim novinama 101/2026. Nije pravni savjet; prije produkcijske uporabe provjerite postavke, podatke i važeće obveze za svoju trgovinu.

## Mogućnosti

- zasebna sidrena cijena za jednostavne proizvode i varijacije
- početno popunjavanje prazne sidrene cijene trenutačnom aktivnom WooCommerce cijenom
- postojeće sidrene cijene nikada se automatski ne prepisuju
- prikaz sidrene cijene na stranici proizvoda, arhivama i drugim WooCommerce popisima
- prilagodljiv tekst, font, stil, boja te veličina za desktop i mobitel
- javni CSV i XML cjenik sa stalnim URL-ovima
- dnevno generiranje i odgođeno osvježavanje nakon promjene proizvoda ili zalihe
- najmanje 30 dana arhive cjenika
- izvoz varijacija, zalihe, kategorija, brenda, barkoda i podataka o posebnom obliku prodaje
- pregled spremnosti podataka, zadnje pogreške i ugrađena pomoć
- podrška za WooCommerce HPOS te Cart i Checkout Blocks

## Zahtjevi

- WordPress 6.0 ili noviji
- WooCommerce 6.0 ili noviji
- PHP 7.4 ili noviji

## Instalacija

1. Preuzmite `sidrena-cijena-v1.1.5.zip` sa stranice [Releases](https://github.com/mgracanin/woocommerce-sidrena-cijena-cjenik/releases/latest).
2. U WordPress administraciji otvorite **Dodaci → Dodaj dodatak → Prenesi dodatak**.
3. Odaberite ZIP, zatim instalirajte i aktivirajte dodatak.
4. Otvorite **Sidrena cijena → Sidrena cijena** te provjerite početno predložene vrijednosti.
5. Na kartici **Cjenik** unesite podatke o prodajnom objektu i kliknite **Generiraj cjenik sada**.

Nadogradnja se trenutačno izvodi ponovnim prijenosom ZIP-a iz novog GitHub izdanja. Dodatak ne sadrži automatsko ažuriranje izvan službenog WordPress direktorija.

## Javni cjenik

Nakon uključivanja odgovarajućeg formata, stalne adrese su:

- `https://vasa-domena.hr/cjenik-proizvoda.csv`
- `https://vasa-domena.hr/cjenik-proizvoda.xml`

Na javnoj stranici mogu se koristiti kratki kodovi:

```text
[sidrena_cjenik]
[sidrena_cjenik format="xml"]
[sidrena_cjenik format="oba"]
[sidrena_cjenik format="oba" arhiva="da"]
```

WP-Cron se pokreće posjetima stranici. Za pouzdano dnevno generiranje prije 8:00 preporučuje se poslužiteljski cron koji redovito poziva `wp-cron.php`.

## Sidrena cijena

Prilikom prve inicijalizacije prazno polje dobiva trenutačnu aktivnu WooCommerce cijenu. Postojeća vrijednost ostaje netaknuta. To olakšava početno postavljanje, ali automatski predloženu vrijednost treba provjeriti prema stvarnoj povijesti cijena i pravnoj obvezi trgovca.

## Pravna podloga

- [Odluka NN 101/2026, broj 1212](https://narodne-novine.nn.hr/clanci/sluzbeni/2026_09_101_1212.html) — prikaz dodatne cijene
- [Odluka NN 101/2026, broj 1213](https://narodne-novine.nn.hr/clanci/sluzbeni/2026_09_101_1213.html) — objava i čuvanje cjenika

Dodatak pruža tehničke alate, ali ne jamči pravnu usklađenost konkretne trgovine. Vlasnik trgovine odgovoran je za točnost podataka, konfiguraciju i pravodobnu objavu.

## Razvoj i provjera

Sintaksa PHP datoteka provjerava se u GitHub Actions na PHP-u 7.4 i 8.3. Lokalni instalacijski paket možete izraditi u PowerShellu:

```powershell
.\scripts\build-release.ps1
```

Paket nastaje u direktoriju `dist/` i sadrži samo datoteke potrebne WordPressu.

Prijave grešaka i prijedlozi dobrodošli su u [Issues](https://github.com/mgracanin/woocommerce-sidrena-cijena-cjenik/issues). Upute za doprinos nalaze se u [CONTRIBUTING.md](CONTRIBUTING.md), a sigurnosne prijave u [SECURITY.md](SECURITY.md).

## Autor

Matija Gračanin — [matijag@gmail.com](mailto:matijag@gmail.com)

## Licenca

Ovaj je dodatak slobodan softver objavljen pod licencom [GNU General Public License v2.0 ili novijom](LICENSE).
