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

/* adresati */
  // Recepce
  $mailToRecepce = ''; // odkomentovat pro přepnutí na produkci
  // TI
  $mailToTI = '';
  // Externi prekladatel
  $mailToExterniPrekladatel = '';

/* headers mailu */
  $mailHeaders = array(
    'MIME-Version' => '1.0',
    'Content-type' => 'text/html; charset=UTF-8',
    'From' => 'Překlady',
    'Reply-To' => ''
  );
/* Nazvy slozek ve slozce uzivatele */
$archiv_name = "Archiv";
$translateExternaly_name = "Přeložit externě";
$externalyTranslated_name = "Od externího překladatele";


// 1. pripojeni do databaze
$sql = pg_connect("host=$pg_server port=$pg_port dbname=$database user=$pg_login password=$pg_pass");

// 2. tady musim ziskat informace o slozkach
# na poradi zalezi! Recepce driv nez ostatni
  // 2.0 Ziskam Klic slozky Translations
    $cmd = 'SELECT "Klic" FROM "DmsSoubor" WHERE "Nazev" = \'Translations\';';
    $res = pg_exec($sql, $cmd);
    $translations_klic = pg_fetch_assoc($res);
  // 2.1 Ziskam Klic slozky Recepce
    $cmd = 'SELECT "Klic" FROM "DmsSoubor" WHERE "Nazev" = \'Recepce\' AND "Smazano" = False AND "Klic" like \''.$translations_klic['Klic'].'%\';';
    $res = pg_exec($sql, $cmd);
    $recepce_klic = pg_fetch_assoc($res);
  // 2.2 ziskani ID "K Nacenění" ve složce recepce 
    $cmd = 'SELECT * FROM "DmsSoubor" WHERE "Nazev" = \'K nacenění\' AND "Smazano" = False AND "Klic" like \''.$recepce_klic['Klic'].'%\';';
    $res = pg_exec($sql, $cmd);
    $fld_k_naceneni = pg_fetch_assoc($res);
  
// 3. Ziskam pole ID slozek ze kterych chci presunout soubory do "K naceneni"
  $cmd = 'SELECT "Id" FROM "DmsSoubor" WHERE "Nazev" = \''.$translateExternaly_name.'\';';
  $res = pg_exec($sql, $cmd);
  while($row = pg_fetch_row($res)){
    // var_dump($row);
    $flds_id_transl_ext = $flds_id_transl_ext.$row[0].",";
  }
  /* Pokud nemam prazdny retezec nebo neni ",":
      - odeberu carku na konci retezce 
      - pokracuju dal -> nastavim $run = true
  */
  if ($flds_id_transl_ext != "" && $flds_id_transl_ext != "," ){
    $flds_id_transl_ext = rtrim($flds_id_transl_ext, ",");
    $flds_id_transl_ext_array = array();
    $flds_id_transl_ext_array = explode(",",$flds_id_transl_ext);
    $continue = true;
    /* TODO - logovat, ze se spustilo a pokracuje dal */
  } else {
    $continue = false;
    $err = "errSourceFolders";
    /* TODO - logovat, ze se spustilo, ale nepokracovalo */
  }
  // var_dump($flds_id_transl_ext);

  // 4. ziskam Id vsech souboru, ktere budu presouvat
    if($continue == true){
      $ids_file_2_transfer = "";
      $cmd = 'SELECT "Id" FROM "DmsSoubor" WHERE "IdParent" IN ('.$flds_id_transl_ext.');';
      $res = pg_exec($sql, $cmd);
      while($row = pg_fetch_row($res)){
        $ids_file_2_transfer = $ids_file_2_transfer.$row[0].",";
      }
      
      if ($ids_file_2_transfer != "" && $ids_file_2_transfer != "," ){
        // odeberu carku z konce stringu
        $ids_file_2_transfer = rtrim($ids_file_2_transfer, ",");
        // Po overeni, ze existuji soubory ve slozkach odkud robot presouva soubory, udelam update jejich nazvu
          foreach ($flds_id_transl_ext_array as $value) {
            // ziskam ID dcerinych souboru
              $cmd = 'SELECT "Id", "Nazev" FROM "DmsSoubor" WHERE "IdParent" = ('.$value.');';
              $res = pg_exec($sql, $cmd);
              $files_ids = array();
              while($row = pg_fetch_row($res)){
                $files_ids[] = $row;
              }
            // pokud mi vrati ID souboru k presunu
              if(!empty($files_ids)){
                // sestavim SQL pro update nazvu, pokud jiz neobsahuje ID parent slozky
                $cmd = '';
                foreach ($files_ids as $val) {
                  // kontrola, ze jeste nebyl soubor prejmenovany
                  if (substr($val[1],0,5) != strval($value)."_"){
                    $cmd = $cmd.'UPDATE "DmsSoubor" SET "Nazev" = CONCAT((SELECT "IdParent" FROM "DmsSoubor" WHERE "Id" = '.$val[0].'),\'_\',(SELECT "Nazev" FROM "DmsSoubor" WHERE "Id" = '.$val[0].')) WHERE "Id" = '.$val[0].';'; 
                  }
                }
                // Ecexute update nazvu souboru
                if ($cmd != ''){
                  $res = pg_exec($sql, $cmd);
                }
              }
          }
        // nastaveni promenych pro spusteni presunu
          $continue = true;
          $err = "noError";
        /* TODO - logovat, ze se spustilo a pokracuje dal */
      } else {
        // nastaveni promenych pro ukonceni presunu
          $continue = false;
          $err= "errPresunSouboru";
        /* TODO - logovat, ze se spustilo, ale nepokracovalo */
      }
      
    }
  // 5. Provedu update, ktery presune soubory do "K naceneni"
    switch ($err) {
      case "noError":
        /**
         * PREDMET EMAILU
         * Musim nadefinovat tady. Pouziva se i v notifikaci pro jednotlive uzivatele.
         */
        /* predmet mailu */
        $mailSubject = '=?UTF-8?B?'.base64_encode('[Překlady] Upozornění').'?=';

        // test file Id = 5990
        // tefs file original IdParent = 5958
        $cmd = 'UPDATE "DmsSoubor" SET "IdParent" = '.$fld_k_naceneni["Id"].' WHERE "Id" IN ('.$ids_file_2_transfer.');';
        $res = pg_exec($sql, $cmd);
        foreach ($ids_file_2_transfer as $idFile) {
          $cmd = 'SELECT \'Email1\' FROM "Uzivatel" WHERE "Id" = (SELECT "IdUzivatelVytvoril" FROM "DmsSoubor" WHERE "Id" = '.$idFile.');';
          $res = pg_exec($sql, $cmd);
          $id_file_creator = array();
          while($row = pg_fetch_row($res)){
            $id_file_creator[] = $row;
          }

          $mailMessageUser = 'Dobrý den,<br><br>
          Na Překlady byl pod Vaším účtem nahrán soubor pro <b>EXTERNÍ PŘEKLAD</b>.
          <ul>
            <li>Soubory byly předány Recepci <b><i>K nacenění</i></b>.</li> 
            <li>Jakmile bude soubor přeložen, obdržíte notifikaci.</i></b>.</li> 
          </ul>
          <br><br>
          Pěkný den,<br><i>robot</i>';

          /* Odeslání notifikace autorovi souboru */
          mail($id_file_creator[0], $mailSubject, $mailMessageUser, $mailHeaders);

        }
        
        /*
          @ 2022-02-23: ALN
          @ Sestaveni těla notifikace a odeslani notifikace o presunu souboru
        */

        /* Obsah zprávy, kterou odesílám na Recepci */
          $mailMessageRecepce = 'Dobrý den,<br><br>
          Na Portal došlo k vložení souborů pro <b>EXTERNÍ PŘEKLAD</b>.
          <ul>
            <li>Soubory byly přesunuty do složky <b><i>K nacenění</i></b>.</li> 
            <li>Do složky se můžete přihlásit pomocí <a href="https://'.$link_subdomena.'.domena.cz/Pages/SprListView.aspx?hideMenu=true&showTask=true&findByKey='.$recepce_klic["Klic"].'" target="_blank">odkazu ZDE</a>.</li>
          </ul>
          <b>Předejte externímu překladateli soubory k nacenění změnou jejich stavu na <i>Předáno</i></b>.<br>
          Vybraný uživatel obdrží notifikaci s okdazy na předané soubory.<br><br>
          <i>TIP: Více souborů předáte současně jejich hromadným označením.</i><br><br>
          Pěkný den,<br><i>robot</i>';

        /* Obsah zprávy, kterou odesílám na T&I */
          $mailMessageTI = 'Dobrý den,<br><br>
          Na Překlady jsem přesunul soubory pro <b>EXTERNÍ PŘEKLAD</b>.
          <ul>
            <li>Soubory byly přesunuty do složky <b><i>K nacenění</i></b>.</li> 
            <li>Odeslal jsem notifikaci na e-mail <i>Recepce</i>.</li> 
          </ul>
          <br><br>
          Pěkný den,<br><i>robot</i>';
        
        /* Obsah zprávy, kterou odesílám na Externiho prekladatele */
          $mailMessageExterniPrekladatel = 'Dobrý den,<br><br>
          Na Portal došlo k vložení souborů pro <b>EXTERNÍ PŘEKLAD</b>.
          <ul>
            <li>Soubory byly přesunuty do složky <b><i>K nacenění</i></b>.</li> 
            <li>Do složky se můžete přihlásit pomocí <a href="https://'.$link_subdomena.'.domena.cz/Pages/SprListView.aspx?hideMenu=true&showTask=true&findByKey='.$recepce_klic["Klic"].'" target="_blank">odkazu ZDE</a>.</li>
          </ul>
          <br><br>
          Pěkný den,<br><i>robot</i>';

        /* Odeslani notifikaci */
          /* Recepce */
          mail($mailToRecepce, $mailSubject, $mailMessageRecepce, $mailHeaders);

          /* Tech innovation */
          mail($mailToTI, $mailSubject, $mailMessageTI, $mailHeaders);

          /* Externi prekladatel */
          mail($mailToExterniPrekladatel, $mailSubject, $mailMessageExterniPrekladatel, $mailHeaders);
        # sem by měl přijít log na 
        break;
      case "errSourceFolders":
        # sem by idealne prisel log na error

        /*
          @ 2022-02-23: ALN
          @ Sestaveni a odeslani notifikace o chybe
        */
          /* predmet mailu */
            $mailSubject = '=?UTF-8?B?'.base64_encode('[Překlady] Error message').'?=';

          /* Obsah zprávy, kterou odesílám na T&I */
            $mailMessageTI = 'Dobrý den,<br><br>
            Na Překlady <b>NEEXISTUJE</b> žádná složka <b>'.$translateExternaly_name.'</b>.<br><br>
            Bude potřeba, aby se na to někdo podíval.<br><br>
            Pěkný den,<br><i>robot</i>';

          /* Odeslani notifikaci */
            mail($mailToTI, $mailSubject, $mailMessageTI, $mailHeaders);
        break;
      case "errPresunSouboru":
        # preruseni odesilani notifikace na TI, kdy neni co presunout 
        # je to tu takhle na prasaka, ale nechcelo se mi to osetrovat
        break;
        # code ...
        # sem by idealne prisel log na error
        /*
          @ 2022-02-23: ALN
          @ Sestaveni a odeslani notifikace o chybe
        */
          /* predmet mailu */
            $mailSubject = '=?UTF-8?B?'.base64_encode('[Překlady] Error message').'?=';

          /* Obsah zprávy, kterou odesílám na T&I */
            $mailMessageTI = 'Dobrý den,<br><br>
            Na <b>Překlady </b> nejsou žádné soubory pro přesun do složky <b>Recepce / K nacenění</b>.<br><br>
            Pěkný den,<br><i>robot</i>';

          /* Odeslani notifikaci */
            mail($mailToTI, $mailSubject, $mailMessageTI, $mailHeaders);
        break;
      default:
        # sem by idealne prisel log na default akci
        break;
    }
?>