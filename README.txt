B2B dla WooCommerce
===================

Wersja: 0.1.0

Instalacja
1. W panelu WordPress przejdź do Wtyczki -> Dodaj nową -> Wyślij wtyczkę na serwer.
2. Wybierz archiwum b2b-woocommerce.zip i aktywuj wtyczkę.
3. W menu administracyjnym pojawi się pozycja „Klienci B2B”.

Funkcje w tej wersji
- tworzenie, edycja i usuwanie kont B2B;
- nazwa konta;
- lista domen przypisanych do konta;
- lista konkretnych adresów e-mail przypisanych do konta;
- zapis w osobnych tabelach bazy danych WordPressa;
- usuwanie tabel przy odinstalowaniu wtyczki.

Struktura danych
- {prefix}b2bw_accounts: nazwa konta i daty utworzenia/aktualizacji;
- {prefix}b2bw_account_domains: domeny powiązane z kontem;
- {prefix}b2bw_account_emails: konkretne adresy e-mail powiązane z kontem.

Ważne
Ta wersja przechowuje konfigurację i udostępnia jej obsługę w panelu. Nie przypisuje jeszcze automatycznie użytkowników WooCommerce do kont B2B i nie zmienia cen produktów. Dopasowanie domeny/adresu oraz obsługa konfliktów między kontami będą częścią kolejnego etapu.
Odinstalowanie wtyczki usuwa utworzone przez nią tabele i wszystkie zapisane w nich dane.
