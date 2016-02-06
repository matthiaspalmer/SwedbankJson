# Introduktion

* [Systemkrav](#systemkrav)
* [Installation](#installation)
* [Komplett exempel](#komplett-exempel)
* [Kontotransaktioner](#kontotransaktioner)
* [Välja konto](#välja-konto)
* [Profilväljare (företag)](#profilväljare-företag)
* [Snabbsaldo](#snabbsaldo)

## Systemkrav

* PHP 5.5+
* Curl

## Installation
Projektet finns på Packagist ([walle89/swedbank-json](https://packagist.org/packages/walle89/swedbank-json)) och kan därmed installeras med [Composer](http://getcomposer.org).

```bash
composer require walle89/swedbank-json ~0.6
```

Mer ingående [instruktioner för installation med Composer](composer.md)

## Komplett exempel
Detta exempel använder [säkerhetsdosa med engångskod](inloggingstyper.md#säkerhetsdosa-med-engångskod) som inloggingstyp för att lista kontotransaktioner. 

```php
<?php 
require 'vendor/autoload.php';

// Inställningar
$bankApp  = 'swedbank';
$username = 8903060000; 

if(empty($_POST['challengeResponse'])
{
   echo '
   <form action="" method="post">
       <p>Fyll i 8-siffrig engångskod från säkerhetsdosa</p>
       <input name="challengeResponse" type="text" />
       <button>Logga in</button>
   </form>';
   exit;
}
if(!is_numeric($_POST['challengeResponse']))
   exit('Fel indata!');

$auth     = new SwedbankJson\Auth\SecurityToken($bankApp, $username, $_POST['challengeResponse']);
$bankConn = new SwedbankJson\SwedbankJson($auth);

$accountInfo = $bankConn->accountDetails();
$bankConn->terminate(); // Utlogging

echo 'Kontoutdrag
<pre>';
print_r($accountInfo);
```

### Föredrar en annan inlogginstyp?
[Lista och instruktioner för respektive inloginstyp](inloggingstyper.md).

## Kontotransaktioner
Lista kontotransaktioner från första kontot.

```php
$accountInfo = $bankConn->accountDetails(); // Hämtar från första kontot, sannolikt lönekontot

$bankConn->terminate(); // Utlogging

echo '<strong>Kontoutdrag</strong>';
print_r($accountInfo);
```

## Välja konto
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

## Profilväljare (företag)
I Swedbanks API finns det stöd för att ha flera företagsprofiler kopplat till sin inlogging. Glöm inte att ändra BANK_APP till ett av Swedbanks företagsappar.

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

## Snabbsaldo 
Ett av få API-anrop som kan helautomatiseras, då det inte kräver någon inlogging. Detta föutsätter att man skaffar ett SubscriptionId (se "[Hur hämtar jag SubscriptionId?](#hur-hämtar-jag-subscriptionid)").
SubscriptionId är ett unikt ID per konto som kan bland annat ge följande information:

* Aktuellt totalsaldo för kontot
* Om det finns eller inte finns notiser för användaren (ex. nyinkomen e-faktura)

Detta ID är tänkt att sparas och användas varje gång man begär snabbsaldo.

```php
<?php 
require 'vendor/autoload.php';

// Inställningar
$bankApp        = 'swedbank';
$subscriptionId = 'ExampleXX2GCi3333YpupYBDZX75sOme8Ht9dtuFAKE=';

$auth     = new SwedbankJson\Auth\UnAuth($bankApp);
$bankConn = new SwedbankJson\SwedbankJson($auth);

echo '<pre>';
var_dump($bankConn->quickBalance($subscriptionId));

```

### Hur hämtar jag SubscriptionId?
Enklast är att använda detta verktyg:

```php
<?php 
require 'vendor/autoload.php';

session_start();

// Inställningar
$bankApp  = 'swedbank';
$username = 8903060000; 

// Inled inloggning
if (!isset($_SESSION['swedbankjson_auth']))
{
    $auth = new SwedbankJson\Auth\MobileBankID($bankApp, $username);
    $auth->initAuth();
    exit('Öppna BankID-appen och godkänn inloggingen. Därefter uppdatera sidan.');
}

// Verifiera inlogging
$auth = unserialize($_SESSION['swedbankjson_auth']);
if (!$auth->verify())
    exit("Du uppdaterade sidan, men inloggningen är inte godkänd i BankID-appen. Försök igen.");

// Inloggad
$bankConn = new SwedbankJson\SwedbankJson($auth);

if (empty($_POST['quickbalanceSubscriptionID']))
{
    $quickBalanceAccounts = $bankConn->quickBalanceAccounts();

    echo '<form action="" method="post"><p>Välj konto för subscriptionId</p><select name="quickbalanceSubscriptionID">';

    foreach ($quickBalanceAccounts->accounts as $account)
        echo '<option value="'.$account->quickbalanceSubscription->id.'">'.$account->name.'</option>';

    echo '</select><button>Skapa prenumeration</button></form>';
    exit;
}

$subInfo = $bankConn->quickBalanceSubscription($_POST['quickbalanceSubscriptionID']);
echo "Kopiera in följande i din kod:<p></p>\$subscriptionId = '{$subInfo->subscriptionId}';";

$auth->terminate(); // Utlogging

```