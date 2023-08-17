<?php
/*
@ 2022-02-23: ALN
@ tento skript bezi na serveruX jako job
  jeho smyslem je presouvat soubory pro externi preklad 
  presouva soubory se slozky zadavetelu do slozky "K naceneni"
*/
// include('../tpl/head.php');
require('../lib/pgsql_operations.php');
// 0. inicializace promennych
$err = "";
$continue = true;
$flds_id_transl_ext = "";

# nastaveni databaze tady, abych to pri zmene nemusel pripadne menit vsude rucne
// # zakomentovat při přepnutí na produkci
  // $database = 'db.test'; 
  // $link_subdomena = 'test';
  // # odkomentovat pro přepnutí na produkci
  $database = 'db.prod'; 
  $link_subdomena = 'translate'; 
  // # definice nazvu slozek
  $folderNameRecepce = 'Recepce';
  $folderNameTransla = 'Translations';
  $folderNamePreklad = 'Překlad';

/* adresati */
  $mailToRecepce = ''; 
  // $mailToTI = ''; // slouží pro testování odesílání notifikací

/* headers mailu */
  // TODO - doplnit Reply-To podle zadání
  $mailHeaders = array(
    'MIME-Version' => '1.0',
    'Content-type' => 'text/html; charset=UTF-8',
    'From' => 'Překlady',
    'Reply-To' => ''
  );
/* predmet mailu */
  $mailSubject = '=?UTF-8?B?'.base64_encode('[Překlady] Error message').'?=';

/* Nazvy slozek ve slozce uzivatele */
// $archiv_name = "Archiv";
// $translateExternaly_name = "Přeložit externě";
$externalyTranslated_name = "Od externího překladatele";


// 1. pripojeni do databaze
$sql = pg_connect("host=$pg_server port=$pg_port dbname=$database user=$pg_login password=$pg_pass");

// 2. tady musim ziskat informace o slozkach
# na poradi zalezi! Recepce driv nez ostatni
  // 2.0 Ziskam Klic slozky Translations
    $cmd = 'SELECT "Klic" FROM "DmsSoubor" WHERE "Nazev" = \''.$folderNameTransla.'\' AND "Smazano" = False;';
    $res = pg_exec($sql, $cmd);
    $translations_klic = pg_fetch_assoc($res);
  // 2.1 Ziskam Klic slozky Recepce
    $cmd = 'Select "Klic" from "DmsSoubor" where "Nazev" = \''.$folderNameRecepce.'\' AND "Smazano" = False AND "Klic" like \''.$translations_klic['Klic'].'%\';';
    $res = pg_exec($sql, $cmd);
    $recepce_klic = pg_fetch_assoc($res);
  // 2.2 ziskani ID "Překlad" ve složce recepce 
    $cmd = 'Select * from "DmsSoubor" where "Nazev" = \''.$folderNamePreklad.'\' AND "Smazano" = False AND "Klic" like \''.$recepce_klic['Klic'].'%\';';
    $res = pg_exec($sql, $cmd);
    $fld_praklad = pg_fetch_assoc($res);
  
// 3. Ziskam pole ID souboru ve slozce "Překlad", ktere chci presunout do slozek uzivatelu
  $cmd = 'Select "Id", "Nazev" from "DmsSoubor" where "IdParent" = \''.$fld_praklad['Id'].'\' AND "Smazano" = False;';
  $res = pg_exec($sql, $cmd);
  $files_preklad = array();
  while($row = pg_fetch_assoc($res)){
    $files_preklad[] = $row;
  }

  # prazdne pole pro relaci slozka uzivatel
  $array_folderId_userEmail = array();

  /* Pokud NENI prazdne pole:
      - ziskam ID parent slozky z nazvu souboru 
      - sestavim UPDATE SQL pro navrat souboru do parent slozky
      - pokracuju dal -> nastavim $continue = true
  */
  if (!empty($files_preklad)){
    $cmd = '';
    foreach ($files_preklad as $file_preklad) {
      # ziskam IdParent z nazvu souboru
      ## spoleham na to, ze je na zacatku nazvu souboru a oddelene "_" (underscore, čili podtržítko)
      if(count(explode('_',$file_preklad['Nazev'],2)) > 1){
		  // ID Prelozit externe je ID ciloveho adresare, tedy "Od Externiho prekladatele" v prisluzne jmenne slozce
        $idPrelozitExterne = intval(explode('_',$file_preklad['Nazev'],2)[0]);
        # pridavam do UPDATE SQL pro každý soubor
        $cmd .= '
          UPDATE "DmsSoubor" 
          SET "IdParent" = (
            Select "Id" 
            FROM "DmsSoubor" 
            WHERE 
              "Nazev" = \'Od externího překladatele\' 
              AND "IdParent" = (
                Select "IdParent" 
                FROM "DmsSoubor" 
                WHERE "Id" = '.$idPrelozitExterne.'
              )
          ) 
          WHERE "Id" = '.$file_preklad['Id'].';';
      }
    }
    
    if($cmd != ''){
      # CONTINUE = true -> pro CMD eecute
      $continue = true;
      $err = "noError";
    } else {
      $continue = false;
      $err = "someError";
    }
    /* TODO - logovat, ze se spustilo a pokracuje dal */
  } else {
    $continue = false;
    $err = "errNoFiles";
    /* TODO - logovat, ze se spustilo, ale nepokracovalo */
	echo "V databazi ".$database." ve slozce ".$folderNameRecepce."/../".$folderNamePreklad." nejosu zadne soubory k preneseni do uzivatelskych slozek.";
  }

  /*
  Condition: CONTINUE = true 
  @ Provedu UPDATE SQL
  */
  if($continue == true && $cmd != ''){
    # execute UPDATE SQL
    $res = pg_exec($sql, $cmd);
    # poslani notifikace na uzivatele
    
  }
  /*
  # ODESILANI NOTIFIKACI
  @ ALN: 2022-04-06
  */
  // @ Připrava pro odeslání notifikace o přesouvání souboru do složky uživatele
  switch ($err) {
    case "noError":
      /*
        @ 2022-02-23: ALN
        @ Sestaveni a odeslani notifikace o presunu souboru
      */
      /* predmet mailu */
        $mailSubject = '=?UTF-8?B?'.base64_encode('[Překlady] Upozornění').'?=';

      /* Obsah zprávy, kterou odesílám na Recepci */
        $mailMessageRecepce = 'Dobrý den,<br><br>
        Na <b>Překlady</b> došlo k přesunu přeložených souborů do složek uživatelů.
        <br><br>
        Pěkný den,<br><i>robot</i>';

      /* Obsah zprávy, kterou odesílám na T&I */
        $mailMessageTI = 'Dobrý den,<br><br>
        Na <b>Překlady</b> došlo k přesunu přeložených souborů do složek uživatelů.
        <br><br>
        Pěkný den,<br><i>robot</i>';

      /* Odeslani notifikaci */
      # Recepce
        mail($mailToRecepce, $mailSubject, $mailMessageRecepce, $mailHeaders);
      # TI
        mail($mailToTI, $mailSubject, $mailMessageTI, $mailHeaders);
      
      // 2022-05-27: Emailové upozornění uživateli "Máš v portálu ten soubor..."
      // Získání informací o uživateli
      if (!empty($files_preklad)){
        foreach ($files_preklad as $file_preklad) {
          # ziskam IdParent z nazvu souboru
          ## spoleham na to, ze je na zacatku nazvu souboru a oddelene "_" (podtrzitkem)
          if(count(explode('_',$file_preklad['Nazev'],2)) > 1){
          // ID Prelozit externe je ID ciloveho adresare, tedy "Od Externiho prekladatele" v prisluzne jmenne slozce
            $idPrelozitExterne = intval(explode('_',$file_preklad['Nazev'],2)[0]);

            $cmd = '
              SELECT "Email1" 
              FROM "Uzivatel" 
              WHERE CONCAT("Jmeno",\' \',"Prijmeni") LIKE (
                SELECT "Nazev" 
                FROM "DmsSoubor" 
                WHERE "Id" = (
                  SELECT "IdParent" 
                  FROM "DmsSoubor" 
                  WHERE "Id" = '.$idPrelozitExterne.'
                )
              )
            ';
            $res = pg_exec($sql, $cmd);
            $row = pg_fetch_assoc($res);
            if($row != false){
              $userEmail = $row['Email1'];
              // pro sestaveni odkazu potrebuji aktualni klic - cely
              $cmd = 'SELECT "Klic" FROM "DmsSoubor" WHERE "Id" = '.$file_preklad['Id'];
              $res = pg_exec($sql, $cmd);
              $row = pg_fetch_assoc($res);
              if($row != false){
                $klic = $row['Klic'];
              } else {
                Echo "Nepodarilo se ziskat sloupec [Klic] pro soubor s [Id] = ".$idPrelozitExterne;
              }
              // sestaveni URL (doplnit dle potreby)
              $link = 'https://'.$link_subdomena.'.domena.cz/Pages/SprListView.aspx?hideMenu=true&showTask=true&findByKey='.$klic;
              $mailMessageUziv = '
                Váš překlad je připraven,<br><br>
                Vámi požadovaný externí překlad byl právě doručen do adresáře <b>'.$externalyTranslated_name.'</b> na portálu. K souboru se dostanete také kliknutím na následující odkaz<br><br>
                <a href="'.$link.'">'.$link.'</a>
                <br><br>
                Pěkný den,<br><i>T&I robot</i>
              ';
              mail($userEmail, $mailSubject, $mailMessageUziv, $mailHeaders);
              
              // pro kontrolu doplnena notifikace na TI, ze se odesila na uzivatele
              $mailMessageUzivProTI = '
                Překlad uživatele '.$userEmail.'je připraven,<br><br>
                Uživatelem požadovaný externí překlad byl právě doručen do adresáře <b>'.$externalyTranslated_name.'</b> na portálu. K souboru se dostanete také kliknutím na následující odkaz<br><br>
                <a href="'.$link.'">'.$link.'</a>

                Toto je pouze kontrolní notifikace, pro otestování funkčnosti.
                <br><br>
                Pěkný den,<br><i>T&I robot</i>
              ';
              mail($mailToTI, $mailSubject, $mailMessageUzivProTi, $mailHeaders);
              
            } else {
              $msg = "Dobrý den,<br><br>
              Na <b>Překlady</b> se nepodařilo dohledat email uživatel, který kontroluje složku #".$idPrelozitExterne." v databázi ".$database.'
              <br><br>
              Pěkný den,<br><i>T&I robot</i>';
              echo $msg;
              mail($mailToTI, $mailSubject, $msg, $mailHeaders);
            }

          }
        }
      }




        
      break;
    case "errNoFiles":
      # preruseni odesilani notifikace na TI, kdy neni co presunout 
      # je to tu takhle na prasaka
      break;
      # sem by idealne prisel log na error
      /*
        @ 2022-02-23: ALN
        @ Sestaveni a odeslani notifikace o chybe
      */
        

        /* Obsah zprávy, kterou odesílám na T&I */
          $mailMessageTI = 'Dobrý den,<br><br>
          Na <b>Překlady</b> nejsou žádné přeložené soubory pro přesun do složky <b>uživatelů</b>.<br><br>
          <br><br>
          Pěkný den,<br><i>T&I robot</i>';

        /* Odeslani notifikaci */
          mail($mailToTI, $mailSubject, $mailMessageTI, $mailHeaders);
      break;
    case "someError":
      # preruseni odesilani notifikace na TI, kdy neni co presunout 
      # je to tu takhle na prasaka
      # sem by idealne prisel log na error
      
      break;
      /*
        @ 2022-02-23: ALN
        @ Sestaveni a odeslani notifikace o chybe
      */
        /* predmet mailu */
          $mailSubject = '=?UTF-8?B?'.base64_encode('[Překlady] Error message').'?=';

        /* Obsah zprávy, kterou odesílám na T&I */
          $mailMessageTI = 'Dobrý den,<br><br>
          Na <b>Překlady</b> nastala <b>NĚJAKÁ CHYBA</b>, když jsem chtěl přesouvat soubory do složky <b>uživatelů</b>.
          <br><br>
          Pěkný den,<br><i>T&I robot</i>';

        /* Odeslani notifikaci */
          mail($mailToTI, $mailSubject, $mailMessageTI, $mailHeaders);
      break;
    default:
      # code...
      # sem by idealne prisel log na default akci
      break;
  }
?>