<?php
/*
@ 2022-02-23: ALN
@ tento skript bezi na serveruY jako job
  jeho smyslem je presouvat soubory pro externi preklad 
  presouva soubory se slozky zadavetelu do slozky "K naceneni"
*/
// include('../tpl/head.php');
require('../lib/pgsql_operations.php');
// 0. inicializace promennych
  // $database = 'db.test'; # nastaveni databaze tady, abych to pri zmene nemusel pripadne menit vsude rucne
  $database = 'dp.prod'; # nastaveni databaze tady, abych to pri zmene nemusel pripadne menit vsude rucne
  $retentionDays = 32; # nastaveni doby pro presun do Archivu. Pocita se pro sloupec DatumZmeny
// 0.1 nazvy slozek ve slozce uzivatele
  $archiv_name = "Archiv";
  $translateExternaly_name = "Přeložit externě";
  $externalyTranslated_name = "Od externího překladatele";

// 1. pripojeni do databaze
  $sql = pg_connect("host=$pg_server port=$pg_port dbname=$database user=$pg_login password=$pg_pass");

// 2. ziskat ID "Podklad" ve složce recepce 
  $cmd = 'SELECT * FROM "DmsSoubor" WHERE "Nazev" = \'Podklad\';';
  $res = pg_exec($sql, $cmd);
  $fld_podklad = pg_fetch_assoc($res);

// 3. ziskat ID "Ověření" ve složce recepce
  $cmd = 'SELECT * FROM "DmsSoubor" WHERE "Nazev" = \'Ověření\';';
  $res = pg_exec($sql, $cmd);
  $fld_overeni = pg_fetch_assoc($res);

// 4. ziskat ID "Translations" (potrebuju pro ziskani slozek uzivatelu)
  $cmd = 'SELECT * FROM "DmsSoubor" WHERE "Nazev" = \'Translations\';';
  $res = pg_exec($sql, $cmd);
  $fld_translations = pg_fetch_assoc($res);

// 4. ziskat ID "Translations" (potrebuju pro ziskani slozek uzivatelu)
  $cmd = 'SELECT * FROM "DmsSoubor" WHERE "Nazev" = \'Recepce\';';
  $res = pg_exec($sql, $cmd);
  $fld_reception = pg_fetch_assoc($res);


// 5. "Podklad"/"Ověření": přesun souborů [starší 32 dní] do složky Archiv
// 6. "Uživatelská folder": přesun souborů [starší 32 dní] INTERNÍ do Archiv 
// 7. "Uživatelská folder": přesun souborů [starší 32 dní] EXT do Archiv
  
  // 3. Ziskam pole ID slozek vsech uzivatelu bez slozky recepce
    $cmd = 'SELECT "Id" FROM "DmsSoubor" WHERE "IdParent" = '.$fld_translations['Id'].' AND "Id" NOT IN ('.$fld_reception['Id'].');';
    $res = pg_exec($sql, $cmd);
      while($row = pg_fetch_row($res)){
        $folder = new Folder($sql,intval($row[0]));
        $folder->move2archive_basic($sql, $folder->data['IdFolder'], $folder->data['IdFldArchiv'], $folder->data['IdExclude']);
        $folder->move2archive_translated($sql, $folder->data['IdFldTranslated'], $folder->data['IdFldArchiv']);
      }

  // 4. Ve slozce "Recepce/Podklad" provedu presun do archivu
    $folder = new Folder($sql,intval($fld_podklad['Id']), $folderType = 'recepce');
    $folder->move2archive_basic($sql, $folder->data['IdFolder'], $folder->data['IdFldArchiv'], $folder->data['IdExclude']);

  // 5. Ve slozce "Recepce/Ověření" provedu presun do archivu
    $folder = new Folder($sql,intval($fld_overeni['Id']), $folderType = 'recepce');
    $folder->move2archive_basic($sql, $folder->data['IdFolder'], $folder->data['IdFldArchiv'], $folder->data['IdExclude']);
      


    class Folder{
      /* Construct function */
      # konstruktor volam pri vytvareni nove instance tridy, abych si naplnil potrebne promenne
      function __construct($sql, $idFolder, $folderType = 'user'){
        $this->data = array();
        $this->data['FolderType'] = $folderType;
        $this->data['IdFolder'] = intval($idFolder);
        $this->_init($sql, $this->data['IdFolder'], $folderType);
      }
      /* 
      # 0. předám SQL a ID složky uživatele (= složka ve které pracuju) [IdFldUser]
      # 1. získám ID složky Archiv ve složce uživatele [IdFldArchiv]
      # 2. získám ID složky Translate Externaly [IdFld4Trans]
      # 3. získám ID složky Externaly Translated [IdFldTranslated]
      # 4. UPDATE "DmsSoubor" SET IdParent = IdFldArchiv WHERE IdParent = IdFldUser && NOT IN (IdFldArchiv,IdFld4Trans,IdFldTranslated) && [starší než 32 dní] 
      # 5. UPDATE "DmsSoubor" SET IdParent = IdFldArchiv WHERE IdParent = IdFldTranslated && [starší než 32 dní] 
      */
      
      # inicializacni funkce
      function _init($sql,$idFolder, $folderType){
        switch ($folderType) {
          # pokud je to slozka recepce
          case 'recepce':
            global $archiv_name;
            // IdFldArchiv
              $this->data['IdFldArchiv'] = $this->getFldId($sql, $idFolder,$archiv_name);
            // String na vynechani slozek pri vyberu
              $this->data['IdExclude'] = "".$this->data['IdFldArchiv']."";
            // ZAKOMENTOVANO, protoze se vytvari samostatna instance tridy pro slozku Podklad a Overeni
            // // IdFldPodklad
            //   $this->data['IdFldPodklad']= $this->getFldId($sql, $idFolder,"Podklad");
            // // IdFldOvereni
            //   $this->data['IdFldOvereni']= $this->getFldId($sql, $idFolder,"Ověření");
            // // String na vynechani slozek pri vyberu
            //   $this->data['IdExclude'] = "".$this->data['IdFldPodklad'].",".$this->data['IdFldOvereni'];
            break;
          # v ostatnich pripadech je to uzivatel
          case 'user':
            # potrebuji globalni promene s nazvy slozek
            global $archiv_name;
            global $translateExternaly_name;
            global $externalyTranslated_name;
            # default nastaveni je USER (slozka uzivatele)
            // IdFldArchiv
              $this->data['IdFldArchiv'] = $this->getFldId($sql, $idFolder, $archiv_name);
            // IdFld4Trans
              $this->data['IdFld4Trans'] = $this->getFldId($sql, $idFolder, $translateExternaly_name);
            // IdFldTranslated
              $this->data['IdFldTranslated'] = $this->getFldId($sql, $idFolder, $externalyTranslated_name);
            // String na vynechani slozek pri vyberu
              $this->data['IdExclude'] = "".$this->data['IdFldArchiv'].",".$this->data['IdFld4Trans'].",".$this->data['IdFldTranslated'];
            break;
        }
      }
      # ziskam ID slozky podle nazvu a IdParent
      function getFldId($sql, $idParent, $nazev){
        $cmd = 'SELECT "Id" FROM "DmsSoubor" WHERE "IdParent" = '.intval($idParent).' AND "Nazev" = \''.$nazev.'\';';
        $res = pg_exec($sql, $cmd);
        $folderId = pg_fetch_assoc($res);
        return intval($folderId['Id']);
      }
      # prasun do archivu
      function move2archive_basic($sql, $idFolder, $idArchive, $exclude){
        global $retentionDays;
        $cmd = 'UPDATE "DmsSoubor" SET "IdParent" = '.$idArchive.' WHERE "IdParent" = '.$idFolder.' AND "Id" NOT IN ('.$exclude.') AND "DatumZmeny" < (now() - \''.$retentionDays.' days\'::interval);';
        $res = pg_exec($sql, $cmd);
      }
      # prasun externe prelozenych souboru do archivu
      function move2archive_translated($sql, $idFolder, $idArchive){
        global $retentionDays;
        $cmd = 'UPDATE "DmsSoubor" SET "IdParent" = '.$idArchive.' WHERE "IdParent" = '.$idFolder.' AND "DatumZmeny" < (now() - \''.$retentionDays.' days\'::interval);';
        $res = pg_exec($sql, $cmd);
      }
    }
    
?>