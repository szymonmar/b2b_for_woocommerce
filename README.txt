B2B dla WooCommerce
===================

Wersja: 0.2.1
Wymagania: WordPress 6.2+, PHP 7.4+, WooCommerce

Instalacja
1. W panelu WordPress przejdź do Wtyczki -> Dodaj nową -> Wyślij wtyczkę na serwer.
2. Wybierz archiwum b2b-woocommerce.zip i aktywuj wtyczkę.
3. W menu administracyjnym pojawi się pozycja „Klienci B2B” oraz podmenu „Ceny preferencyjne”.

Konta B2B
- tworzenie, edycja i usuwanie kont B2B;
- nazwa konta;
- lista domen przypisanych do konta;
- lista konkretnych adresów e-mail przypisanych do konta.

Ceny preferencyjne
- reguły przypisane do konta B2B;
- rabat procentowy od ceny regularnej produktu;
- cena stała dla produktu prostego lub konkretnego wariantu;
- reguły dla produktu, konkretnego wariantu albo kategorii;
- reguła wariantu ma pierwszeństwo przed regułą produktu, a reguła produktu przed regułą kategorii;
- jeśli pasuje kilka reguł kategorii, stosowana jest najniższa obliczona cena;
- cena B2B zastępuje cenę promocyjną;
- ceny są stosowane przez filtry WooCommerce i nie zmieniają zapisanych cen katalogowych;
- reguły i ceny wariantów są buforowane w ramach żądania, a hash cen wariantów uwzględnia konto B2B.

Rozpoznawanie konta
- najpierw sprawdzany jest dokładny adres e-mail użytkownika;
- jeśli nie ma dokładnego dopasowania, sprawdzana jest domena;
- jeśli dopasowanie wskazuje kilka kont, ceny B2B nie są stosowane, aby uniknąć przypisania niejednoznacznego konta.

Struktura danych
- {prefix}b2bw_accounts: nazwa konta i daty utworzenia/aktualizacji;
- {prefix}b2bw_account_domains: domeny powiązane z kontem;
- {prefix}b2bw_account_emails: konkretne adresy e-mail;
- {prefix}b2bw_price_rules: reguły cenowe kont B2B.

Reguły cenowe
- produkt: rabat procentowy obejmuje także warianty produktu nadrzędnego; cena stała dozwolona dla produktu prostego;
- wariant: reguła dotyczy konkretnego wariantu;
- kategoria: rabat procentowy dla produktów w kategorii;
- cena stała nie jest dostępna dla kategorii ani produktu wariantowego jako całości.

Odinstalowanie
Plik uninstall.php usuwa wszystkie tabele wtyczki i wersję schematu bazy danych.
