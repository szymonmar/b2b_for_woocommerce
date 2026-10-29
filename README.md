# B2B dla WooCommerce

**Wersja:** 1.0.0  
**Wymagania:** WordPress 6.2 lub nowszy, PHP 7.4 lub nowszy, WooCommerce  
**Text Domain:** `b2b-woocommerce`

## Opis

**B2B dla WooCommerce** umożliwia definiowanie kont kontrahentów B2B i przypisywanie im indywidualnych reguł cenowych. Ceny są wyliczane na podstawie ceny regularnej produktu lub konkretnego wariantu, a następnie udostępniane przez mechanizmy cenowe WooCommerce.

Wtyczka pozwala między innymi na:

- przypisywanie klientów do kont B2B na podstawie adresu e-mail lub domeny;
- ustawianie rabatów procentowych i cen stałych;
- definiowanie reguł dla produktów, wariantów i kategorii;
- rozstrzyganie konfliktów pomiędzy pasującymi regułami według określonego priorytetu;
- wyświetlanie informacji **„Cena indywidualna”** na stronie produktu;
- współpracę z wtyczką **Lowest Price History** w zakresie etykiety dotyczącej najniższej ceny z 30 dni.

Wtyczka nie zmienia zapisanych w katalogu cen produktów — ceny preferencyjne są stosowane dynamicznie dla rozpoznanego klienta B2B.

## Spis treści

- [Wymagania](#wymagania)
- [Instalacja](#instalacja)
- [Konfiguracja kont B2B](#konfiguracja-kont-b2b)
- [Konfiguracja cen preferencyjnych](#konfiguracja-cen-preferencyjnych)
- [Zasady naliczania cen](#zasady-naliczania-cen)
- [Rozpoznawanie konta B2B](#rozpoznawanie-konta-b2b)
- [Informacja „Cena indywidualna”](#informacja-cena-indywidualna)
- [Integracja z Lowest Price History](#integracja-z-lowest-price-history)
- [Struktura danych](#struktura-danych)
- [Odinstalowanie](#odinstalowanie)

## Wymagania

- WordPress 6.2 lub nowszy;
- PHP 7.4 lub nowszy;
- aktywna wtyczka WooCommerce.

Integracja etykiety Omnibus wymaga dodatkowo wtyczki **Lowest Price History**. Bez niej podstawowe funkcje kont B2B i reguł cenowych pozostają niezależne od tej integracji.

## Instalacja

1. Zaloguj się do panelu administracyjnego WordPress.
2. Przejdź do **Wtyczki → Dodaj nową → Wyślij wtyczkę na serwer**.
3. Wybierz archiwum `b2b-woocommerce.zip`.
4. Zainstaluj archiwum i aktywuj wtyczkę.
5. W menu administracyjnym pojawi się sekcja **Klienci B2B** oraz podmenu **Ceny dla Klientów B2B**.

Przed aktualizacją lub instalacją na sklepie produkcyjnym zalecane jest wykonanie kopii zapasowej plików i bazy danych.

## Konfiguracja kont B2B

Konta konfiguruje się w panelu WordPress w sekcji **Klienci B2B**. Każde konto ma nazwę oraz listę domen i konkretnych adresów e-mail, które pozwalają przypisać zalogowanego użytkownika do kontrahenta.

### Dodawanie konta

1. Otwórz **Klienci B2B**.
2. Dodaj konto i wpisz jego nazwę, np. nazwę firmy lub kontrahenta.
3. W polu domen wpisz domeny przypisane do konta, np. `firma.pl`.
4. W polu adresów e-mail wpisz adresy, które mają być przypisane bezpośrednio do konta, np. `zakupy@firma.pl`.
5. Zapisz konto.

W polach domen i adresów e-mail można podać wiele wartości — każdą w osobnym wierszu lub oddzieloną przecinkiem albo średnikiem. Domeny należy wpisywać bez protokołu i ścieżki, np. `firma.pl`, a nie `https://firma.pl/sklep`.

Przypisanie domeny oznacza, że konto może obejmować różnych użytkowników logujących się adresami z tej domeny. Należy dodawać wyłącznie domeny, których przypisanie do kontrahenta jest zamierzone.

### Edycja i usuwanie

W sekcji **Klienci B2B** można edytować dane konta lub je usunąć. Reguły cenowe są przypisane do identyfikatora konta. Przed usunięciem konta sprawdź, czy nie jest ono nadal potrzebne do obsługi kontrahenta.

## Konfiguracja cen preferencyjnych

Reguły konfiguruje się w **Klienci B2B → Ceny dla Klientów B2B**.

### Dodawanie reguły

1. Wybierz konto B2B, dla którego ma obowiązywać reguła.
2. Wybierz **Zakres reguły**:
   - **Produkt (także wariantowy)** — reguła dla produktu; rabat procentowy obejmuje również jego warianty;
   - **Konkretny wariant** — reguła tylko dla wskazanego wariantu;
   - **Kategoria produktów** — reguła dla produktów należących do wskazanej kategorii.
3. Wybierz produkt, wariant lub kategorię, zależnie od zakresu reguły.
4. Wybierz **Rodzaj ceny**:
   - **Rabat procentowy** — procent rabatu liczony od ceny regularnej;
   - **Cena stała** — konkretna cena w walucie sklepu.
5. Wpisz wartość i zapisz regułę.

Po zapisaniu reguła pojawi się na liście obowiązujących reguł dla wybranego konta. Istniejącą regułę można usunąć z tej listy.

### Dostępne typy reguł

| Zakres | Rabat procentowy | Cena stała |
| --- | --- | --- |
| Produkt prosty | Tak | Tak |
| Produkt wariantowy jako całość | Tak — obejmuje warianty | Nie |
| Konkretny wariant | Tak | Tak |
| Kategoria produktów | Tak | Nie |

Cena stała dla produktu wariantowego jako całości nie jest dostępna, ponieważ poszczególne warianty mogą mieć różne ceny. Aby ustawić konkretną cenę dla danego wariantu, utwórz regułę typu **Konkretny wariant**.

## Zasady naliczania cen

### Kolejność reguł

Jeśli dla produktu pasuje więcej niż jedna reguła, stosowana jest reguła o wyższym priorytecie:

1. **Konkretny wariant** — najwyższy priorytet;
2. **Produkt** — kolejny priorytet;
3. **Kategoria produktów** — najniższy priorytet.

Jeżeli pasuje kilka reguł kategorii, wtyczka wybiera najniższą obliczoną cenę.

### Sposób obliczania ceny

- Rabat procentowy jest liczony od ceny regularnej produktu lub wariantu.
- Cena stała określa bezpośrednio cenę preferencyjną.
- Cena B2B zastępuje cenę promocyjną WooCommerce, jeśli dla danego produktu lub wariantu obowiązuje cena preferencyjna.
- Ceny katalogowe zapisane w produkcie nie są nadpisywane przez wtyczkę.
- Reguły i ceny wariantów są buforowane w ramach pojedynczego żądania. Hash cen wariantów uwzględnia konto B2B, aby ograniczyć ryzyko użycia bufora cen innego kontrahenta.

## Rozpoznawanie konta B2B

Ceny preferencyjne są stosowane na podstawie adresu e-mail **zalogowanego użytkownika**.

Wtyczka sprawdza przypisanie w następującej kolejności:

1. Szuka dokładnego adresu e-mail na liście przypisanych adresów.
2. Jeśli nie znajdzie dokładnego dopasowania, sprawdza domenę adresu e-mail.
3. Jeśli dopasowanie wskazuje dokładnie jedno konto B2B, stosuje jego reguły cenowe.
4. Jeśli dopasowanie wskazuje kilka kont, nie stosuje cen B2B — zapobiega to niejednoznacznemu przypisaniu kontrahenta.

W praktyce oznacza to, że użytkownik musi zalogować się na konto WordPress/WooCommerce z adresem e-mail przypisanym do odpowiedniego konta B2B albo należącym do przypisanej domeny. Samo dodanie adresu lub domeny do konfiguracji nie tworzy konta użytkownika i nie loguje klienta automatycznie.

## Informacja „Cena indywidualna”

Wtyczka udostępnia akcję WordPress:

```php
do_action( 'b2bw_before_product_price', $product );
```

Akcja wyświetla etykietę **„Cena indywidualna”**, gdy dla bieżącego produktu lub wariantu zalogowanego klienta istnieje cena preferencyjna.

### Umieszczenie etykiety w szablonie produktu

Aby wyświetlić informację w konkretnym miejscu strony produktu, dodaj wywołanie akcji w szablonie WooCommerce lub szablonie motywu potomnego. Przykładowo, bezpośrednio przed wyświetleniem ceny:

```php
<?php do_action( 'b2bw_before_product_price', $product ); ?>
<strong><?php woocommerce_template_single_price(); ?></strong>
```

W tym przykładzie `$product` powinien być obiektem aktualnego produktu WooCommerce. Jeśli motyw korzysta z własnego szablonu produktu, umieść wywołanie w odpowiednim miejscu tego szablonu. Zalecane jest wprowadzanie zmian w motywie potomnym, aby aktualizacja motywu nadrzędnego ich nie nadpisała.

**Uwaga:** samo zarejestrowanie akcji przez wtyczkę nie gwarantuje wyświetlenia etykiety. Szablon musi wywołać `do_action( 'b2bw_before_product_price', $product )`.

### Produkty wariantowe

Dla produktu wariantowego wtyczka przygotowuje pojedynczy element informacji i listę identyfikatorów wariantów objętych ceną indywidualną. Informacja jest pokazywana tylko wtedy, gdy wybrany wariant ma przypisaną cenę preferencyjną. Przy wyborze innego wariantu etykieta jest aktualizowana przez skrypt front-endowy wtyczki.

Dla produktu prostego etykieta jest wyświetlana, jeśli dla tego produktu istnieje cena preferencyjna.

## Integracja z Lowest Price History

Jeśli sklep korzysta z wtyczki **Lowest Price History**, integracja może zmienić etykietę informującą o najniższej cenie z 30 dni przed obniżką na **„Cena indywidualna”**, gdy dla bieżącego produktu lub wariantu obowiązuje cena preferencyjna B2B.

Dzięki temu na stronie produktu nie jest prezentowana standardowa etykieta Omnibus w miejscu, w którym powinna być widoczna informacja o indywidualnej cenie dla danego klienta.

### Ważne informacje

- Wtyczka **Lowest Price History** musi być zainstalowana i aktywna, aby działała jej część integracji.
- Integracja etykiety nie zastępuje samodzielnie oceny obowiązków prawnych sprzedawcy związanych z prezentowaniem najniższej ceny przed obniżką.
- Sposób prezentowania informacji powinien być sprawdzony na stronie produktu, w tym dla produktów wariantowych oraz dla użytkowników zalogowanych i niezalogowanych.

## Struktura danych

Wtyczka tworzy własne tabele w bazie danych WordPress. Nazwy tabel uwzględniają prefiks instalacji:

- `{prefix}b2bw_accounts` — nazwy kont B2B oraz daty utworzenia i aktualizacji;
- `{prefix}b2bw_account_domains` — domeny przypisane do kont;
- `{prefix}b2bw_account_emails` — konkretne adresy e-mail przypisane do kont;
- `{prefix}b2bw_price_rules` — reguły cenowe kont B2B.

Wtyczka przechowuje również opcję wersji schematu bazy danych, wykorzystywaną do obsługi aktualizacji struktury.

## Uprawnienia

Zarządzanie kontami B2B i regułami cenowymi jest dostępne dla użytkowników WordPress, którzy mają uprawnienie `manage_woocommerce`. Wyszukiwanie produktów i wariantów w formularzu administracyjnym dodatkowo sprawdza uprawnienie `edit_products`.

## Odinstalowanie

Podczas odinstalowania wtyczka usuwa własne tabele:

- `{prefix}b2bw_price_rules`;
- `{prefix}b2bw_account_emails`;
- `{prefix}b2bw_account_domains`;
- `{prefix}b2bw_accounts`;

oraz opcję `b2bw_db_version`.

**Uwaga:** odinstalowanie jest operacją usuwającą dane konfiguracji wtyczki z bazy danych. Przed odinstalowaniem wykonaj kopię zapasową, jeśli chcesz zachować konta lub reguły cenowe.

## Informacje o wydaniu

### 1.0.0

- zarządzanie kontami B2B i przypisaniami przez domeny oraz adresy e-mail;
- reguły cenowe dla produktów, wariantów i kategorii;
- priorytety reguł oraz wybór najniższej ceny przy wielu pasujących regułach kategorii;
- dynamiczne stosowanie cen preferencyjnych przez mechanizmy WooCommerce;
- etykieta „Cena indywidualna” na stronie produktu;
- obsługa etykiety dla odpowiednich wariantów produktów;
- integracja z Lowest Price History w zakresie etykiety Omnibus.
