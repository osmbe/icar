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

$Verbose=5;

/* sqllite handle */
$db=false;

/* sqllite templates */
$create_filemap=<<<EOD
CREATE TABLE filemap (
imei bigint(20) NOT NULL,
path varchar(255) NOT NULL,
map_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
PRIMARY KEY (imei,path) ON CONFLICT REPLACE
);
EOD;

$insert_filemap=<<<EOD
INSERT INTO filemap VALUES (%s, '%s', DATETIME('NOW'));
EOD;

$select_filemaps=<<<EOD
SELECT imei, path FROM filemap;
EOD;

$select_filemap=<<<EOD
SELECT path FROM filemap WHERE imei=%s;
EOD;
$ev_base="/opt/strac/";

$ev_light_dir="/opt/strac/ev_data/";

/* Sqlite DB fast imei list */
$imeis_bdb=$ev_base . "/imeis.sqlite";
/* Sqlite DB fast storage */
$file_map_bdb=$ev_base . "/files-lcd.sqlite";

$imeis=getImeis($imeis_bdb,array('arcelormittal','eyke'));

open_db($db, $file_map_bdb , "filemap", $create_filemap);
logtrace(1,"Storing imei/path mappings");

//print_r($imeis); exit;

foreach($imeis as $kaske) {
   logtrace(4,sprintf("Scanning for %s path mappings",$kaske['imei']));
   //$file_list = bfglob($ev_light_dir. "old/",sprintf("Event_%s",$kaske['imei']),0,3);
   $known=getMaps($file_map_bdb, $kaske['imei']);

   //print_r($known);
   $known_filemaps=array_value_recursive('path', $known);
   //print_r($known_filemaps);

//exit;

   // $filename_path = sprintf("%s%s", $data_dir, $dir_per_day);

   //$dir_per_day=date('Y/m/d');
   $dir_per_day=sprintf("%s/%s",date('Y/m'),"*");
   $file_template = sprintf("Event_%s",$kaske['imei']);
   $path_template = sprintf("%s%s%s",$ev_light_dir, "lcd/",$dir_per_day);

   logtrace(2,sprintf("Path %s",$path_template));
/*
   if(!file_exists($path_template)) {
      logtrace(4,sprintf("Path doesn't exist for %s path %s",$kaske['imei'],$path_template));
      continue;
   }
*/

   logtrace(4,sprintf("Scanning imei %s for files in %s",$kaske['imei'],$path_template));

   if (count($known_filemaps)) {
      logtrace(4,sprintf("Found existing paths(%d) for imei %s",count($known_filemaps),$kaske['imei']));
      // print_r($known_filemaps); exit;
   }
   
   // bfglob($path, $pattern = 'Event_*', $flags = 0, $depth = 5) 
   $file_list = bfglob($path_template,$file_template,0,3);
   logtrace(3,sprintf("Found %d file(s) for imei %s",count($file_list), $kaske['imei']));
//exit;
   if (count($file_list)) {
      $db->queryExec("BEGIN;");
      $qr="";
      foreach($file_list as $file) {
         if (!in_array($file, $known_filemaps)) {
            logtrace(2,sprintf("Storing path %s for imei %s",$file, $kaske['imei']));
            $qr.=sprintf($insert_filemap, $kaske['imei'], $file);
         } else {
            logtrace(4,sprintf("Not storing known path %s for imei %s",$file, $kaske['imei']));
         }
      }
      @$db->queryExec($qr,$error);
      if (!empty($error)){
         logtrace(0,"Error : " . $error);
         exit();
      }
      $db->queryExec("COMMIT;");
      // sleep(1);
   }
}

function open_db(&$db, $database="", $table="", $create_query="")  {
   /* avoid crap */
   if (isset($database) and isset($table)) {
      if (strlen($database)<=0 or strlen($table)<=0) {
         return -1;
      }
   } else {
      return -1;
   }

   $db = new SQLiteDatabase($database, 0666, $err);
   if ($err) {
      trigger_error($err);
      return($err);
   }

   /* test for table inside DB */
   $q = @$db->query(sprintf("PRAGMA table_info(%s)",$table));
   if ($q->numRows() < 3) {
      if(strlen($create_query)>0) {
         @$db->queryExec(sprintf($create_query, $table), $error);
         if (!empty($error)){
            logtrace(1,"Error : " . $error);
            exit();
         }
      }
   } else {
      $result = $q->fetchSingle();
   }
   return(1);
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

function bfglob($path, $pattern = 'Event_*', $flags = 0, $depth = 5) {

   print_r( func_get_args());
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

function getMaps($imeis_bdb,$imei) {
   global $select_filemap;

   print_r( func_get_args());

   // SELECT imei, path FROM filemap WHERE imei=%s;

   $results=array();
   if (empty($imeis_bdb)) { return $results ; }

   /* sqllite handle */
   $db=false;

   // SELECT path FROM filemap WHERE imei=%s;
   $get_maps = sprintf($select_filemap,$imei);
   logtrace(3, sprintf("[%s] - execute query '%s' on %s",__FUNCTION__, $get_maps, $imeis_bdb));

   if(!file_exists($imeis_bdb)) {
      logtrace(0,sprintf("Cant open database %s",$imeis_bdb));
      return array();
   } else {
      if ($db = new SQLiteDatabase($imeis_bdb)) {
         $db->busyTimeout(8000); // 8 seconds
         $q = $db->query($get_maps);
         if ($q->numRows() > 0) {
            $results = $q->fetchAll(SQLITE_ASSOC);
         }
      }
   }
   return($results);
}


function getImeis($imeis_bdb,$account) {

   $results=array();
   if (empty($imeis_bdb)) { return $results ; }
   if (empty($account)) { return $results ; }

   /* sqllite handle */
   $db=false;
   //$get_imeis = sprintf("SELECT imei FROM Device where account='%s' and imei=351777043756004 ORDER by imei DESC",$account);
   if (is_array($account)) {
      $dd_list=array();
      foreach($account as $val) {
         $dd_list[]=sprintf("'%s'",$val);
      }
      $imei_list = sprintf("(%s)",implode(',',$dd_list));
      $get_imeis = sprintf("SELECT imei FROM Device where account IN %s ORDER by account, imei DESC",$imei_list);
   } else {
      $get_imeis = sprintf("SELECT imei FROM Device where account='%s' ORDER by imei DESC",$account);
   }
   logtrace(5, sprintf("[%s] - execute query '%s'",__FUNCTION__, $get_imeis));

   if(!file_exists($imeis_bdb)) {
      logtrace(0,sprintf("Cant open database %s",$imeis_bdb));
      return array();
   } else {
      if ($db = new SQLiteDatabase($imeis_bdb)) {
         $db->busyTimeout(8000); // 8 seconds
         $q = $db->query($get_imeis);
         if ($q->numRows() > 0) {
            $results = $q->fetchAll(SQLITE_ASSOC);
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

?>
