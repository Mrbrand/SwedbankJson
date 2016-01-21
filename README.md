# SwedbankJson

Inofficiell wrapper för det API som används för Swedbanks och Sparbankernas mobilappar (privatperson, ungdom och företag). Det finns flertalet stöd för olika inloggningstyper:

* Mobilt BankID
* Säkerhetsdosa med engångskod
* Ingen inlogging (för vissa funktioner, tex. snabbsaldo) 

**Detta kan wrappen göra**

* Översikt av tillgängliga konton så som lönekonto, sparkonton investeringsbesparningar, lån, bankkort och kreditkort.
* Lista ett kontos samtliga transaktioner.
* Företagsinloggingar kan välja att lista konton utifrån en vald profil.
* Aktivera, avaktivera och visa snabbsaldo.
* Kommunicationen sker krypterat enbart med Swedbankds servrar utan mellanhänder.
* Autentiseringsnyckel som krävs för inlogging genereras automatiskt per session (standard) eller manuellt sätta en statisk nykel.

[Fler funktioner finns planerade](https://github.com/walle89/SwedbankJson/labels/todo).

## Kodexempel

### Grund (Personlig kod)
Grundkoden för exemplen nedan:
```php
require_once 'vendor/autoload.php';

// Inställningar
define('BANK_APP',  'swedbank');     // Byt mot motsvarande IOS/Android mobil app. Alternativ: swedbank, sparbanken, swedbank_foretag, sparbanken_foretag, swedbank_ung, sparbanken_ung
define('USERNAME',  198903060000);   // Person- eller organisationsnummer
define('PASSWORD',  'fakePW');       // Personlig kod

$auth = new SwedbankJson\Auth\PersonalCode(BANK_APP, USERNAME, PASSWORD);
$bankConn = new SwedbankJson\SwedbankJson($auth);
```
Men vill man använda en annan inloggigstyp än personlig kod behöver man modifera ovanstånde kod till ett av förjande:

#### Säkerhetsdosa (Engångskod)
Det finns två typer av varianter för inlogging med säkerhetsdosa. Ett av dessa är engångskod, som ger ett 8-siffrig kod när man har låst upp dosan och väljer 1 när "Appli" visas.

Utgår man från inlogginsflöde i mobilappen ser den ut som följande:

**Välj säkerhetsdosa -> Fyll i engångskod från säkerhetsdosan -> Inloggad**

```php
$auth = new SwedbankJson\Auth\SecurityToken(BANK_APP, USERNAME, $challengeResponse);
```
**$challengeResponse** ska vara ett 8-siffrigt nummer som man får från säkerhetsdosan

#### Säkerhetsdosa (Kontrollnummer och svarskod)
Den andra typen av inlogginsmetod för säkerhetsdosa är kontrollnummer med svarskod. Denna metod innebär att man får en 8-siffrigt kontrollnummer som ska matas in i dosan och som svar får man ett nytt 8-siffrigt svarskod som skrivs in i antingen appen eller i internetbanken.

Utgår man från inlogginsflöde i mobilappen ser den ut som följande:

**Välj säkerhetsdosa -> Mata in kontrollnummer i dosan -> Skriv av savarskod -> Inloggad**

I dagsläget finns det inget stöd för denna typ av inlogging, men den finns på todo-listan. Den som kan tänka sig att ställa upp som testare kan läsa mer om det [här](https://github.com/walle89/SwedbankJson/issues/18#issuecomment-77850071).

### Kontotransaktioner
Lista kontotransaktioner från första kontot.
```php
$accountInfo = $bankConn->accountDetails(); // Hämtar från första kontot, sannolikt lönekontot

$bankConn->terminate(); // Utlogging

echo '<strong>Kontoutdrag</strong>';
print_r($accountInfo);
```

### Välja konto
För att lista och välja ett specifikt konto som man hämtar sina transaktioner kan man modifiera ovanstående kod till följande:
```php
$accounts = $bankConn->accountList(); // Lista på tillgängliga konton

$accountInfo = $bankConn->accountDetails($accounts->transactionAccounts[1]->id); // För konto #2 (gissningsvis något sparkonto)

$bankConn->terminate(); // Utlogging

echo '<strong>Konton</strong>';
print_r($accounts);

echo '<strong>Kontoutdrag</strong>';
print_r($accountInfo);
```

### Profilväljare (företag)
I Swedbanks API finns det stöd för att ha flera företagsprofiler kopplat till sin inlogging. Glöm inte att ändra BANK_APP till an av Swedbanks företagsappar.
```PHP
$profiles = $bankConn->profileList(); // Profiler

$accounts = $bankConn->accountList($profiles->corporateProfiles[0]->id); // Tillgängliga konton utifrån vald profil

$accountInfo = $bankConn->accountDetails($accounts->transactionAccounts[0]->id);

$bankConn->terminate(); // Utlogging

echo '<strong>Profiler</strong>';
print_r($profiles);

echo '<strong>Konton</strong>';
print_r($profiles);

echo '<strong>Kontoutdrag</strong>';
print_r($accountInfo);
```

## Systemkrav

* PHP 5.5+
* Curl

## Installation
Idag erbjuds enbart installation med [Composer](http://getcomposer.org). Installation som görs på andra sätt rekommenderas inte och ges ingen support för.

### Linux och OS X

Kör följande i ett terminalfönster (OS X: Öppna Applikationer > Verktygsprogram > Terminal):
```bash
curl -sS https://getcomposer.org/installer | php
```

Lägg in SwebankJson i composer.json antingen med följande kommando:
```bash
php composer.phar require walle89/swedbank-json ~0.5
```
Efter lyckad installation, ladda in autoload.php.

```php
require 'vendor/autoload.php';
```

### Windows

Se till att php.exe finns installerat och den fulla sökvägen till den (ex. C:\php\php.exe).

Kör sedan [Compoer-Setup.exe](https://getcomposer.org/doc/00-intro.md#using-the-installer) och följ instruktionerna samt se till att "Shell menus" installeras.

Skapa eller ändra composer.json med följande innehåll:
```javascript
{
    "require": {
        "walle89/swedbank-json": "~0.5"
    }
}
```

Högerklicka på composer.json och välj "Composer Install". Efter lyckad installation, ladda in autoload.php.
```php
require 'vendor/autoload.php';
```

## Dokumentation

Finns i form av PHPDoc kommentarer i filerna. Utförligare dokumentation med API-anrop finns på [todo-listan](https://github.com/walle89/SwedbankJson/wiki/Todo).

## Uppdateringar

Främsta anledningen till uppdateringar behöver göras är att Swedbank ändrar AppID och User Agent för varje uppdatering av sina appar. AppID och User Agent används som en del av atuetensierings prosessen.

### Linux och OS X
Kör följande kommando:
```bash
php composer.phar update
```

### Windows
Högerklicka på den katalog som innehåller composer.json, högerklicka och välj "Composter update".

## Feedback, frågor, buggar, etc.

Skapa en [Github Issue](https://github.com/walle89/SwedbankJson/issues), men var god kontrollera att det inte finns någon annan som skapat en likande issue (sökfunktinen är din vän).

## Andra projekt med Swedbanks API
* [SwedbankSharp](https://github.com/DarkTwisterr/SwedbankSharp) av [DarkTwisterr](https://github.com/DarkTwisterr) - C# med .NET implementation.
* [Swedbank-Cli](https://github.com/spaam/swedbank-cli) av [Spaam](https://github.com/spaam) - Swedbank i terminalen. Skriven i Python.
* [SwedbankJson](https://github.com/viktorgardart/SwedbankJson) av [Viktor Gardart](https://github.com/viktorgardart) - Objective-C implementation (för iOS).

## Licens (MIT)
Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.