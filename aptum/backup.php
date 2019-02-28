#!/usr/bin/php -q
<?php

/* call with -d to debug */
$cmd_list=arguments( $argv );

if (!isset($cmd_list)) {
   //logtrace(1,"Called without arguments");
   $no_args=1;
}

if (isset($cmd_list['d']) && $cmd_list['d']==1 && $no_args!=1) {
   logtrace(1,"Debug mode");
   $debug=true;
   // does nothing atm
}

$Verbose=4;


# connecting some SQLite DB
# we'll actually use an IN-MEMORY DB
# so to avoid any further complexity;
# an IN-MEMORY DB simply is a temp-DB 

/* sqllite handle */
$db = null;

/* sqllite templates */
$create_streets=<<<EOD
CREATE TABLE streets (
id INTEGER PRIMARY KEY AUTOINCREMENT,
postcode int NOT NULL,
sname varchar(255) NOT NULL,
fname varchar(255) NOT NULL,
map_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY(postcode) REFERENCES filemap(postcode)
);
EOD;

$create_geos=<<<EOD
SELECT AddGeometryColumn('streets', 'geom1', 4326, 'POINT', 'XY');
SELECT AddGeometryColumn('streets', 'geom2', 4326, 'POINT', 'XY');
EOD;


$index_streets=<<<EOD
CREATE INDEX idx_pc_sn ON streets (postcode, sname);
EOD;

$create_addresses=<<<EOD
CREATE TABLE addresses (
id INTEGER PRIMARY KEY AUTOINCREMENT,
sname varchar(255) NOT NULL,
app_nr varchar(255) DEFAULT NULL,
bus_nr varchar(255) DEFAULT NULL,
house_nr varchar(255) DEFAULT NULL,
map_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
FOREIGN KEY(sname) REFERENCES streets(sname)
);
EOD;

$create_adresses_geos=<<<EOD
SELECT AddGeometryColumn('addresses', 'coord', 4326, 'POINT', 'XY');
EOD;

$create_filemap=<<<EOD
CREATE TABLE filemap (
postcode int NOT NULL,
path varchar(255) NOT NULL,
map_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (postcode,path) ON CONFLICT REPLACE
);
EOD;

$insert_filemap=<<<EOD
INSERT INTO filemap VALUES (%s, '%s', DATETIME('NOW'));
EOD;

$insert_streets=<<<EOD
INSERT INTO streets (postcode, sname, fname, map_date) VALUES ( %d, '%s', '%s', DATETIME('NOW'));
EOD;


$select_filemaps=<<<EOD
SELECT * FROM filemap where postcode='%s';
EOD;

$select_streets=<<<EOD
SELECT * FROM streets where postcode='%s';
EOD;

$select_all_streets=<<<EOD
SELECT * FROM streets order by postcode;
EOD;

$select_some_streets=<<<EOD
SELECT * FROM streets where postcode='%s' AND sname IN (%s);
EOD;

$select_allfilemaps=<<<EOD
SELECT * FROM filemap;
EOD;

$select_filemap=<<<EOD
SELECT * FROM filemap WHERE postcode='%s';
EOD;

// $db = new SQLite3(':memory:');

$db_file = 'agiv.sqlite';
//$db_file = ':memory:';
$postcodes_index_path = sprintf("%s","/var/www/aptum/data/");

open_db($db, $db_file, $table="filemap", $create_filemap) ;
open_db($db, $db_file, $table="streets", $create_streets, array($index_streets, $create_geos)) ;
open_db($db, $db_file, $table="addresses", $create_addresses, array($create_adresses_geos)) ;
//open_db($db, 'agiv.sqlite' , $table="filemap", $create_filemap, array($create_streets, $create_geos)) ;
//exit;


//bfglob($path, $pattern = '*.json', $flags = 0, $depth = 1) {

$file_list = bfglob($postcodes_index_path,'*.json',0,0);
$known_filemaps=array();
logtrace(3,sprintf("Found %d file(s)",count($file_list)));
if (count($file_list)) {
    $db->exec("BEGIN;");
    $qr="";
    foreach($file_list as $file) {
        $postcode=basename($file, ".json");
        if (!in_array($file, $known_filemaps)) {
            logtrace(5,sprintf("Storing path %s for postcode %s",$file, $postcode));
            $qr.=sprintf($insert_filemap, $postcode, $file);
        } else {
            logtrace(1,sprintf("Not storing known path %s for postcode %s",$file, $postcode));
        }
    }
    if (!$db->exec($qr)) {
        logtrace(0,"Error : " . $db->lastErrorMsg());
        exit();
    } else {
        $db->exec("COMMIT;");
    }
}
$known=getMaps($db);
///print_r($known);
//print_r($known);
$known_filemaps=array_value_recursive('path', $known);
//print_r($known_filemaps);

foreach ($known_filemaps as $fname) {
    $string = file_get_contents($fname);
    $json_a = json_decode($string, true);
    //print_r($json_a);exit;

    if (!empty($json_a['streets'])) {
        $db->exec("BEGIN;");
        foreach ($json_a['streets'] as $k => $v) {
            $qr=sprintf($insert_streets, basename($fname,'.json'), SQLite3::escapeString($v['name']), SQLite3::escapeString($v['sanName']));
            logtrace(5,sprintf($qr));
            logtrace(5,sprintf("\"%s\" from file '%s'",SQLite3::escapeString($v['name']), SQLite3::escapeString($v['sanName'])));
            if (!$db->exec($qr)) {
                logtrace(0,"Error : " . $db->lastErrorMsg());
                logtrace(0,"record : " . print_r($v,true));
                logtrace(0,"qry : " . $qr);
                $db->exec("ROLLBACK;");
            }
        }
        $db->exec("COMMIT;");
    }
}

$known=getStreets($db);
logtrace(1, sprintf("[%s] Count %d",__FUNCTION__, count($known)));

# this time we'll use a Prepared Statement
$qry = sprintf("INSERT INTO addresses ( sname, app_nr, bus_nr, house_nr, coord) VALUES ( ?, ?, ? , ?, GeomFromText(?, 4326))");
logtrace(3,sprintf("[%d] - Preparing %s",0, $qry));
$stmt = $db->prepare($qry);
foreach ($known as $k => $file) {
    $filename = $postcodes_index_path.$file['postcode']."/".$file['fname'].".json";
    if (!($k % 500)) {
        logtrace(3,sprintf("[%d] - Parsing %s file",$k , $filename));
    }
    $string = file_get_contents($filename);
    $json_a = json_decode($string, true);
    //print_r($json_a);exit;

    if (count($json_a)) {
        $db->exec("BEGIN");
        $stmt->reset();
        $stmt->clear();
        foreach($json_a as $key => $this_address) {
            $this_address = array_pop($this_address);
            // $qry = sprintf("INSERT INTO addresses ( sname, bus_nr, house_nr, coord)  VALUES ( ?, ?, GeomFromText('POINT(? ?)', 4326))", $file['sname'], json_encode($file['busnrs']), json_encode($file['hnrlbls']),  $file['lat'], $file['lon']);
            if (!($k % 100)) {
                logtrace(3,sprintf("[] - Binding vars to qry"));
            }
            $stmt->bindValue(1, $file['sname'], SQLITE3_TEXT);
            $stmt->bindValue(2, (isset($this_address['apptnrs']) ? json_encode($this_address['apptnrs']) : NULL ) , SQLITE3_TEXT);
            $stmt->bindValue(3, (isset($this_address['busnrs']) ? json_encode($this_address['busnrs']) : NULL ) , SQLITE3_TEXT);
            $stmt->bindValue(4, (isset($this_address['hnrlbls']) ? json_encode($this_address['hnrlbls']) : NULL ) , SQLITE3_TEXT);
            $stmt->bindValue(5, sprintf("POINT(%s %s)", $this_address['lat'],$this_address['lon']), SQLITE3_TEXT);
            $stmt->execute();
//var_dump($this_address);exit;
        } 
        //logtrace(3,sprintf("[] - Committing"));
        $db->exec("COMMIT");
    }
}
    /*
sname varchar(255) NOT NULL,
house_address varchar(255) NOT NULL,
bus_nr varchar(255) NOT NULL,
house_nr varchar(255) NOT NULL,
map_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,

   {
      "busnrs": [
        "1",
        "2",
        "3",
        "4"
      ],
      "hnrlbls": [
        "100"
      ],
      "housenumber": "100",
      "lat": 50.97467359648,
      "lon": 4.468016210346,
      "municipality": "Zemst",
      "pcode": "1982",
      "source": "afgeleidVanGebouw",
      "street": "Damstraat"
    },
*/
//$known_streetmaps=array_value_recursive('path', $known);

unset($db);

exit;

# reporting some version info
$rs = $db->query('SELECT sqlite_version()');
while ($row = $rs->fetchArray()) {
  logtrace(2, "SQLite version: $row[0]");
}
$rs = $db->query('SELECT spatialite_version()');
while ($row = $rs->fetchArray()) {
  logtrace(2, "SpatiaLite version: $row[0]");
}


# creating a POINT table
$sql = "CREATE TABLE test_pt (";
$sql .= "id INTEGER NOT NULL PRIMARY KEY,";
$sql .= "name TEXT NOT NULL)";
$db->exec($sql);
# creating a POINT Geometry column
$sql = "SELECT AddGeometryColumn('test_pt', ";
$sql .= "'geom', 4326, 'POINT', 'XY')";
$db->exec($sql);

# creating a LINESTRING table
$sql = "CREATE TABLE test_ln (";
$sql .= "id INTEGER NOT NULL PRIMARY KEY,";
$sql .= "name TEXT NOT NULL)";
$db->exec($sql);
# creating a LINESTRING Geometry column
$sql = "SELECT AddGeometryColumn('test_ln', ";
$sql .= "'geom', 4326, 'LINESTRING', 'XY')";
$db->exec($sql);

# creating a POLYGON table
$sql = "CREATE TABLE test_pg (";
$sql .= "id INTEGER NOT NULL PRIMARY KEY,";
$sql .= "name TEXT NOT NULL)";
$db->exec($sql);
# creating a POLYGON Geometry column
$sql = "SELECT AddGeometryColumn('test_pg', ";
$sql .= "'geom', 4326, 'POLYGON', 'XY')";
$db->exec($sql);

# inserting some POINTs
# please note well: SQLite is ACID and Transactional
# so (to get best performance) the whole insert cycle
# will be handled as a single TRANSACTION
$db->exec("BEGIN");
for ($i = 0; $i < 10000; $i++)
{
  # for POINTs we'll use full text sql statements
  $sql = "INSERT INTO test_pt (id, name, geom) VALUES (";
  $sql .= $i + 1;
  $sql .= ", 'test POINT #";
  $sql .= $i + 1;
  $sql .= "', GeomFromText('POINT(";
  $sql .= $i / 1000.0;
  $sql .= " ";
  $sql .= $i / 1000.0;
  $sql .= ")', 4326))";
  $db->exec($sql);
}
$db->exec("COMMIT");

# checking POINTs
$sql = "SELECT DISTINCT Count(*), ST_GeometryType(geom), ";
$sql .= "ST_Srid(geom) FROM test_pt";
$rs = $db->query($sql);
while ($row = $rs->fetchArray())
{
  # read the result set
  $msg = "Inserted ";
  $msg .= $row[0];
  $msg .= " entities of type ";
  $msg .= $row[1];
  $msg .= " SRID=";
  $msg .= $row[2];
  print "<h3>$msg</h3>";
}

# inserting some LINESTRINGs
# this time we'll use a Prepared Statement
$sql = "INSERT INTO test_ln (id, name, geom) ";
$sql .= "VALUES (?, ?, GeomFromText(?, 4326))";
$stmt = $db->prepare($sql);
$db->exec("BEGIN");
for ($i = 0; $i < 10000; $i++)
{
  # setting up values / binding
  $name = "test LINESTRING #";
  $name .= $i + 1;
  $geom = "LINESTRING(";
  if (($i%2) == 1)
  {
    # odd row: five points
    $geom .= "-180.0 -90.0, ";
    $geom .= -10.0 - ($i / 1000.0);
    $geom .= " ";
    $geom .= -10.0 - ($i / 1000.0);
    $geom .= ", ";
    $geom .= -10.0 - ($i / 1000.0);
    $geom .= " ";
    $geom .= 10.0 + ($i / 1000.0);
    $geom .= ", ";
    $geom .= 10.0 + ($i / 1000.0);
    $geom .= " ";
    $geom .= 10.0 + ($i / 1000.0);
    $geom .= ", 180.0 90.0";
  }
  else
  {
    # even row: two points
    $geom .= -10.0 - ($i / 1000.0);
    $geom .= " ";
    $geom .= -10.0 - ($i / 1000.0);
    $geom .= ", ";
    $geom .= 10.0 + ($i / 1000.0);
    $geom .= " ";
    $geom .= 10.0 + ($i / 1000.0);
  }
  $geom .= ")";

  $stmt->reset();
  $stmt->clear();
  $stmt->bindValue(1, $i+1, SQLITE3_INTEGER);
  $stmt->bindValue(2, $name, SQLITE3_TEXT);
  $stmt->bindValue(3, $geom, SQLITE3_TEXT);
  $stmt->execute();
}
$db->exec("COMMIT");

# checking LINESTRINGs
$sql = "SELECT DISTINCT Count(*), ST_GeometryType(geom), ";
$sql .= "ST_Srid(geom) FROM test_ln";
$rs = $db->query($sql);
while ($row = $rs->fetchArray())
{
  # read the result set
  $msg = "Inserted ";
  $msg .= $row[0];
  $msg .= " entities of type ";
  $msg .= $row[1];
  $msg .= " SRID=";
  $msg .= $row[2];
  print "<h3>$msg</h3>";
}

# insering some POLYGONs
# this time too we'll use a Prepared Statement
$sql = "INSERT INTO test_pg (id, name, geom) ";
$sql .= "VALUES (?, ?, GeomFromText(?, 4326))";
$stmt = $db->prepare($sql);
$db->exec("BEGIN");
for ($i = 0; $i < 10000; $i++)
{
  # setting up values / binding
  $name = "test POLYGON #";
  $name .= $i + 1;
  $geom = "POLYGON((";
  $geom .= -10.0 - ($i / 1000.0);
  $geom .= " ";
  $geom .= -10.0 - ($i / 1000.0);
  $geom .= ", ";
  $geom .= 10.0 + ($i / 1000.0);
  $geom .= " ";
  $geom .= -10.0 - ($i / 1000.0);
  $geom .= ", ";
  $geom .= 10.0 + ($i / 1000.0);
  $geom .= " ";
  $geom .= 10.0 + ($i / 1000.0);
  $geom .= ", ";
  $geom .= -10.0 - ($i / 1000.0);
  $geom .= " ";
  $geom .= 10.0 + ($i / 1000.0);
  $geom .= ", ";
  $geom .= -10.0 - ($i / 1000.0);
  $geom .= " ";
  $geom .= -10.0 - ($i / 1000.0);
  $geom .= "))";

  $stmt->reset();
  $stmt->clear();
  $stmt->bindValue(1, $i+1, SQLITE3_INTEGER);
  $stmt->bindValue(2, $name, SQLITE3_TEXT);
  $stmt->bindValue(3, $geom, SQLITE3_TEXT);
  $stmt->execute();
}
$db->exec("COMMIT");

# checking POLYGONs
$sql = "SELECT DISTINCT Count(*), ST_GeometryType(geom), ";
$sql .= "ST_Srid(geom) FROM test_pg";
$rs = $db->query($sql);
while ($row = $rs->fetchArray())
{
  # read the result set
  $msg = "Inserted ";
  $msg .= $row[0];
  $msg .= " entities of type ";
  $msg .= $row[1];
  $msg .= " SRID=";
  $msg .= $row[2];
  print "<h3>$msg</h3>";
}

# closing the DB connection
$db->close();

function open_db(&$db, $database="", $table="", $create_query="", $extra_query="")  {
    logtrace(3, sprintf("[%s] - Start",__FUNCTION__));
    /* so we all understand,   $table = $database minus the path; */
    /* avoid crap */

    $return = 0;
    if (isset($database) and isset($table)) {
        if (strlen($database)<=0 or strlen($table)<=0) {
            logtrace(2, sprintf("[%s] - Check content of parameters",__FUNCTION__));
            return($return);
        }
    } else {
        logtrace(0, sprintf("[%s] - Missing parameters",__FUNCTION__));
        return($return);
    }

    logtrace(2, sprintf("[%s] - Trying to open sqlite DB %s",__FUNCTION__,$database));

    /* if already open, don't reopen 

       sqlite> PRAGMA table_info(filemap)
       ...> ;
       0|postcode|int|1||1
       1|path|varchar(255)|1||2
       2|map_date|timestamp|1|CURRENT_TIMESTAMP|0
       sqlite> PRAGMA table_info(filemap);
       0|postcode|int|1||1
       1|path|varchar(255)|1||2
       2|map_date|timestamp|1|CURRENT_TIMESTAMP|0
       sqlite> PRAGMA database_list;
       0|main|/var/www/aptum/aptum/agiv.sqlite
       sqlite> 
     */

    $db_ok = false;
    if($db) {
        /* test to see if we have this DB open */
        $rows=0;
        logtrace(1, sprintf("[%s] Pragma check",__FUNCTION__));
        $q = $db->query(sprintf("PRAGMA database_list"));
        while ($row = $q->fetchArray(SQLITE3_ASSOC)) {
            $rs = $row;
            $rows++;
            logtrace(5, sprintf("[%s] - : %s",__FUNCTION__,print_r($row,true)));
        }
        logtrace(1, sprintf("[%s] Count %d",__FUNCTION__, $rows));
        logtrace(1, sprintf("[%s] %s",__FUNCTION__, json_encode($rs)));

        if ($rows > 0) {
            logtrace(1, sprintf("[%s] check %s vs %s",__FUNCTION__, $rs['file'], $database));
            if ((strcmp($rs['file'], $database) === 0 ) || ( $rs['seq'] == 0 && $rs['name'] == 'main')) {
                logtrace(1, sprintf("[%s] - Already have this DB open : %s",__FUNCTION__,$database));
                $db_ok  = true;
            }
        }
    }

    if (!$db_ok) { $db = new SQLite3($database, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE); }

    // var_dump($db);exit;

    # loading SpatiaLite as an extension
    if (!$db_ok) { $db->loadExtension('libspatialite.so'); }

    # enabling Spatial Metadata
    # using v.2.4.0 this automatically initializes SPATIAL_REF_SYS
    # and GEOMETRY_COLUMNS
    if (!$db_ok) { $db->exec("SELECT InitSpatialMetadata()"); }

    $db->busyTimeout(7000); // 7 seconds

    if(!$db) {
        logtrace(0, sprintf("[%s] - Problem opening DB %s",__FUNCTION__,$database));
        logtrace(0, $err);
	    exit;
    }

    logtrace(2, sprintf("[%s] - Opened %s",__FUNCTION__,$database));
    /*
       logtrace(1,sprintf("Problem with qry : %s",$db->lastErrorMsg()));
     */

    /* test for table inside DB */
    $rows=0;
    $q = @$db->query(sprintf("PRAGMA table_info(%s)",$table));
    while ($row = $q->fetchArray(SQLITE3_ASSOC)) {
        $rs[] = $row;
        $rows++;
    }

    if ($rows < 3) {
        logtrace(1, sprintf("[%s] - table pragma check: Rows %s",__FUNCTION__, $rows ));
        if(strlen($create_query)>0) {
			logtrace(1, sprintf("[%s] - Creating table %s",__FUNCTION__,$table));
            logtrace(2, sprintf("[%s] - Query %s",__FUNCTION__,sprintf($create_query, $table)));
            if(!$db->exec(sprintf($create_query, $table))){
                logtrace(0,sprintf("[%s] - Failed to create table %s",__FUNCTION__,$table));
                logtrace(0,sprintf("%s",$create_query));
                exit;
            } else {
                logtrace(2,sprintf("[%s] - Table %s created.",__FUNCTION__,$table));
                if(!empty($extra_query)) {
                    // sprintf($extra, $table);
                    /*

                       2012-09-23 00:45:22 [10040]:[1]core  - [open_db] - Creating extras:( CREATE INDEX idx_cmb_ss_range ON %s(imei, gps_date, raw_data);
                       CREATE INDEX idx_si_gps_date ON %s(gps_date); ) on Event_arcelormittal_all
                       PHP Notice:  Undefined variable: extra in /opt/strac/serv_track_slite_light.php on line 3716
                       PHP Warning:  SQLiteDatabase::exec(): Cannot execute empty query. in /opt/strac/serv_track_slite_light.php on line 3716

                     */
                    if (!empty($extra_query)) {
                        if (!is_array($extra_query)) {
                            $ex=array();
                            $ex[]=$extra_query;
                            $extra_query=$ex;
                        }
                        foreach($extra_query as $extra) {
                            if(!$db->exec($extra)){
                                logtrace(0, sprintf("[%s] - Problem creating extras %s",__FUNCTION__,$extra));
                                logtrace(0, sprintf("[%s] - %s",__FUNCTION__,$db->lastErrorMsg()));
                                exit;
                            } else {
                                logtrace(1, sprintf("[%s] - Created extras: %s",__FUNCTION__,$extra));
                            }
                        }
                    }
                }
                $return=1;
            }
        } else {
            logtrace(0,sprintf("[%s] - Missing create query for table %s.",__FUNCTION__,$table));
        }
    } else {
        logtrace(2, sprintf("[%s] - table %s exist already",__FUNCTION__,$table));
        $return=1;
        // $result = $q->fetchSingle();
    }
    return($return);
}

function logtrace($level,$msg) {
   global $Verbose, $cmd_list, $no_args, $LogBatch;

   $DateTime=@date('Y-m-d H:i:s', time());

   if ( $level <= $Verbose ) {
      $mylvl=NULL;
      switch($level) {
         case 0:
            $mylvl ="error";
            break;
         case 1:
            $mylvl ="core ";
            break;
         case 2:
            $mylvl ="info ";
            break;
         case 3:
            $mylvl ="notic";
            break;
         case 4:
            $mylvl ="verbs";
            break;
         case 5:
            $mylvl ="dtail";
            break;
         default :
            $mylvl ="exec ";
            break;
      }
      // 2008-12-08 15:13:06 [31796] - [1] core    - Changing ID
      //"posix_getpid()=" . posix_getpid() . ", posix_getppid()=" . posix_getppid();
      $content = $DateTime. " [" .  posix_getpid() ."]:[" . $level . "]" . $mylvl . " - " . $msg . "\n";

      echo $content;
      /* called with -d to skip deamonizing , don't write to log cos process ID's are the same*/
      $ok=0;
   }
}


function arguments($argv) {
   $ARG = array();
   foreach ($argv as $arg) {
      if (strpos($arg, '--') === 0) {
         $compspec = explode('=', $arg);
         $key = str_replace('--', '', array_shift($compspec));
         $value = join('=', $compspec);
         $ARG[$key] = $value;
      } elseif (strpos($arg, '-') === 0) {
         $key = str_replace('-', '', $arg);
         if (!isset($ARG[$key])) $ARG[$key] = true;
      }
   }
   return $ARG;
}

function bfglob($path, $pattern = '*.json', $flags = 0, $depth = 1) {

   // print_r( func_get_args());
   $matches = array();
   $folders = array(rtrim($path, DIRECTORY_SEPARATOR));

   while($folder = array_shift($folders)) {
      // echo $folder . PHP_EOL;
      $matches = array_merge($matches, glob($folder.DIRECTORY_SEPARATOR.$pattern, $flags));
      if($depth != 0) {
         $moreFolders = glob($folder.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR);
         $depth   = ($depth < -1) ? -1: $depth + count($moreFolders) - 2;
         $folders = array_merge($folders, $moreFolders);
      }
   }
   // print_r());
   return $matches;
}

function getMaps($bdb,$postcode = null) {
   global $select_filemap, $select_allfilemaps;

   $results=null;

   // print_r( func_get_args());

   // SELECT imei, path FROM filemap WHERE imei=%s;

   $results=array();
   if (empty($bdb)) { return $results ; }

   /* sqllite handle */
   $db=false;

   // SELECT path FROM filemap WHERE postcode=%s;
   if (empty($postcode)) { 
       $get_maps = sprintf($select_allfilemaps,$postcode);
   } else {
       $get_maps = sprintf($select_filemap,$postcode);
   }

   if(!$bdb) {
       //logtrace(0,sprintf("Cant open database %s",$bdb));
       return array();
   } else {
      if ($bdb) {
         $bdb->busyTimeout(8000); // 8 seconds
   	    logtrace(3, sprintf("[%s] - execute query '%s'",__FUNCTION__, $get_maps));
	 $q = $bdb->query($get_maps);

         $results=array();
         if ($q) {
            while ( $row = $q->fetchArray(SQLITE3_ASSOC)) {
               $results[] = $row;
            }
         } else {
            logtrace(0,"Cant exec %s",$db->lastErrorMsg());
         }
      }
   }
   return($results);
}

function getStreets($bdb,$postcode = null) {
   global $select_streets, $select_all_streets;

   $results=null;

   // print_r( func_get_args());

   // SELECT imei, path FROM filemap WHERE imei=%s;

   $results=array();
   if (empty($bdb)) { return $results ; }

   /* sqllite handle */
   $db=false;

   // SELECT path FROM filemap WHERE postcode=%s;
   if (empty($postcode)) { 
       $get_maps = sprintf($select_all_streets);
   } else {
       $get_maps = sprintf($select_streets,$postcode);
   }

   if(!$bdb) {
       //logtrace(0,sprintf("Cant open database %s",$bdb));
       return array();
   } else {
       if ($bdb) {
           $bdb->busyTimeout(8000); // 8 seconds
           logtrace(3, sprintf("[%s] - execute query '%s'",__FUNCTION__, $get_maps));
           $q = $bdb->query($get_maps);

           $results=array();
           if ($q) {
            while ( $row = $q->fetchArray(SQLITE3_ASSOC)) {
               $results[] = $row;
            }
         } else {
            logtrace(0,"Cant exec %s",$db->lastErrorMsg());
         }
      }
   }
   return($results);
}


function array_value_recursive($key, array $arr){
    $val = array();
    array_walk_recursive($arr, function($v, $k) use($key, &$val){
        if($k == $key) array_push($val, $v);
    });
    return (array) count($val) > 1 ? $val : array_pop($val);
}

function resource_test($resource, $name) {
    echo 
        '[' . $name. ']',
        PHP_EOL,
        '(bool)$resource => ',
        $resource ? 'TRUE' : 'FALSE',
        PHP_EOL,
        'get_resource_type($resource) => ',
        get_resource_type($resource) ?: 'FALSE',
        PHP_EOL,
        'is_resoruce($resource) => ',
        is_resource($resource) ? 'TRUE' : 'FALSE',
        PHP_EOL,
        PHP_EOL
    ;
}




?>
