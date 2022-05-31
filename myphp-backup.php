<?php 
/*************************************************************\
*                                                             *
* This file contains the Backup_Database class wich performs
* a partial or complete backup of any given MySQL database
* @author version 1.0 Daniel López Azaña <daniloaz@gmail.com>
* 
* Updated in 2019-2022 by Nibbik - Anjer Apps <info@anjer.net>
* now includes TRIGGERS and FUNCTIONS and PROCEDURES and VIEWS
* when complete database backup (if TABLES='*')
* now possible to do several backups consecutively
* @version 2.7 - may 2022
*                                                             *
\*************************************************************/

/**
 * Define database parameters here
 */

$DB_USER='...';
$DB_PASSWORD= '...';
$DB_NAME= '...';
$DB_HOST= 'localhost';
$BACKUP_DIR= '.'; // Comment this line to use same script's directory ('.')
$TABLES= '*'; // Full backup
//$TABLES= 'table1, table2, table3'); // Partial backup
$CHARSET= 'utf8mb4'; // 'utf8'

date_default_timezone_set("Europe/Amsterdam");
define("GZIP_BACKUP_FILE", false); // Set to false if you want plain SQL backup files (not gzipped)
define("DISABLE_FOREIGN_KEY_CHECKS", true); // Set to true if you are having foreign key constraint fails
define("BATCH_SIZE", 100);  // Batch size when selecting rows from database in order to not exhaust system memory
                            // Also number of rows per INSERT statement in backup file
$f_silent=0;

{   // error reporting
    ini_set('error_reporting', E_ALL);
    //ini_set('display_errors', 1);
    ini_set('html_errors', 1);
    ini_set('log_errors', 1);
    ini_set('error_log',getcwd()."/error_log.txt");
    // Set script max execution time
    @set_time_limit(900); // 15 minutes
}

if(isset($_REQUEST['silent']) && intval($_REQUEST['silent'])!= 0) {
    $f_silent=1;
}

Backup_Db ($DB_USER, $DB_PASSWORD, $DB_NAME, $DB_HOST, $BACKUP_DIR, $TABLES, $CHARSET);
Backup_Db ("...", "...", "...", $DB_HOST, $BACKUP_DIR, $TABLES, $CHARSET);
Backup_Db ("...", "...", "...", $DB_HOST, $BACKUP_DIR, $TABLES, $CHARSET);
//http_response_code (200);


/**
 * The Backup_Database class
 */
class Backup_Database {
    /**
     * Host where the database is located
     */
    var $host;

    /**
     * Username used to connect to database
     */
    var $username;

    /**
     * Password used to connect to database
     */
    var $passwd;

    /**
     * Database to backup
     */
    var $dbName;

    /**
     * Database charset
     */
    var $charset;

    /**
     * Database connection
     */
    var $conn;

    /**
     * Backup directory where backup files are stored 
     */
    var $backupDir;

    /**
     * Output backup file
     */
    var $backupFile;

    /**
     * Use gzip compression on backup file
     */
    var $gzipBackupFile;

    /**
     * Content of standard output
     */
    var $output;

    /**
     * Disable foreign key checks
     */
    var $disableForeignKeyChecks;

    /**
     * Batch size, number of rows to process per iteration
     */
    var $batchSize;

    /**
     * Constructor initializes database
     */
    public function __construct($host, $username, $passwd, $dbName, $charset = 'utf8mb4', $silent = 0) {
        $this->host                    = $host;
        $this->username                = $username;
        $this->passwd                  = $passwd;
        $this->dbName                  = $dbName;
        $this->charset                 = $charset;
        $this->conn                    = $this->initializeDatabase();
        $this->backupDir               = $GLOBALS['BACKUP_DIR'] ? $GLOBALS['BACKUP_DIR'] : '.';
        $this->backupFile              = 'backup-'.$this->dbName.'-'.date("Ymd_His", time()).'.sql';
        $this->gzipBackupFile          = defined('GZIP_BACKUP_FILE') ? GZIP_BACKUP_FILE : true;
        $this->disableForeignKeyChecks = defined('DISABLE_FOREIGN_KEY_CHECKS') ? DISABLE_FOREIGN_KEY_CHECKS : true;
        $this->batchSize               = defined('BATCH_SIZE') ? BATCH_SIZE : 1000; // default 1000 rows
        $this->output                  = '';
    }

    protected function initializeDatabase() {
        try {
            $conn = mysqli_connect($this->host, $this->username, $this->passwd, $this->dbName);
            if (mysqli_connect_errno()) {
                throw new Exception('ERROR connecting database: ' . mysqli_connect_error());
                die();
            }
            if (!mysqli_set_charset($conn, $this->charset)) {
                mysqli_query($conn, 'SET NAMES '.$this->charset);
            }
            mysqli_query($conn, 'SET SQL_BIG_SELECTS=1');
        } catch (Exception $e) {
            print_r($e->getMessage());
            die();
        }
        
        return $conn;
    }

    /**
     * Backup the whole database or just some tables
     * Use '*' for whole database or 'table1 table2 table3...'
     * @param string $tables
     */
    public function backupTables($tables = '*', $BACKUP_DIR) {
        try {
            /**
             * Tables to export
             */
            if($tables == '*') {
                $procedures = '*';
                $functions = '*';
                $views = '*';
                $tables = array();
                $result = mysqli_query($this->conn, 'SHOW FULL TABLES');
                while($row = mysqli_fetch_row($result)) {
                    $tables[] = $row[0];
                    $tabletype[$row[0]] = $row[1];
                }
            } else {
                $tables = is_array($tables) ? $tables : explode(',', str_replace(' ', '', $tables));
            }

            $sql = 'CREATE DATABASE IF NOT EXISTS `'.$this->dbName."`;\n\n";
            $sql .= 'USE `'.$this->dbName."`;\n\n";

            /**
             * Disable foreign key checks 
             */
            if ($this->disableForeignKeyChecks === true) {
                $sql .= "SET foreign_key_checks = 0;\n\n";
            }

            $sql .= "/**/\n --  TABLES";

            /**
             * Iterate tables
             */
            foreach($tables as $table) {
                $this->obfPrint("Backing up `".$table."` table..".str_repeat('.', max(51-strlen($table),0)), 0, 0);
                $sql.="\n/**/\n\n";
                
                /** 
                 * CHECK IF TABLE IS VIEW
                 */
                $f_is_view = ($tabletype[$table]==="VIEW");

                /**
                 * CREATE TABLE
                 */
                $errno = 0;
                if ($f_is_view) {
                    mysqli_query($this->conn, "CREATE TEMPORARY TABLE `$table` AS SELECT * FROM `$table` LIMIT 0,0;");
                    // prevent creating view - do this at the end - but instead create temporary table with same name to mimick output structure of view
                    // if view and temporary table with same name exist, SHOW CREATE TABLE will default to temp table structure:
                    $errno = intval($this->conn->errno);
                    if ($errno) {
                        header('X-PHP-Response-Code: 424', true, 424);
                        $this->obfPrint("Error $errno -> illegal VIEW definition commented-out in backup!");
                        if (intval($GLOBALS['f_silent'])>0) {
                            echo "<pre>TABLEs: Illegal view definition in `$table` in database {$this->dbName} commented-out in backup! (error $errno) </pre>\n";
                        }
                    } else {
                        $sql .= "-- Temporary table as stand-in for VIEW `$table`: \n";
                    }
                }
                $sql .= "DROP TABLE IF EXISTS `$table`;\n";
                $sql .= ($f_is_view ? "DROP VIEW IF EXISTS `$table`;\n\n" : "\n");

                $row = mysqli_fetch_row(mysqli_query($this->conn, 'SHOW CREATE TABLE `'.$table.'`'));

                $createtable = preg_replace('/CREATE TEMPORARY TABLE/', 'CREATE TABLE', $row[1], 1); 
                // remove TEMPORARY (only first occurrence) from definition because otherwise creation of views based on this
                // view (that is, the temporary stand-in table) will fail because views cannot be based on temporary tables
                
                // check for GENERATED COLUMNs  -  Step 1
                //  as inserting values into generated columns will cause errors, this will impair database restoration
                //  solution: on table definition remove the calculation info for generated columns, 
                //  after data is inserted alter table to make columns generated again
                //
                { /* // PM: no longer neccessary as in later versions the insert statement only inserts for non-generated columns
                    $altertable = "";
                    $pattern = '/^\s*((`.+`)\s.+(GENERATED.+)),?$/miU'; 
                    preg_match_all($pattern, $createtable, $matches);
                        // whole match: whole line for generated column including comma at the end (if there is one)
                        // group 1: line for generated column excluding leading spaces and trailing comma,
                        // group 2: only column-name and surrounding back-ticks
                        // group 3: only definition 
                    for ($x = 0; $x < count($matches[0]) ; $x++) {
                        $altertable .= "ALTER TABLE `$table` CHANGE {$matches[2][$x]} {$matches[1][$x]};\r\n";
                        $createtable = str_replace($matches[3][$x], "DEFAULT NULL", $createtable);
                    }
                */ }
                
                // now add the table definition (PM: if $errno then create temp table definition failed so probably illegal view definition)
                $sql .= ($errno!=0 ? "-- ILLEGAL VIEW DEFINITION ???\n/*\n" : "") .$createtable.";". ($errno!=0 ? "\n*/" : "") ."\n";

                if (!$f_is_view) 
                {   // temporary tables as stand-in for views do not need to be filled with data/triggers
                    // if you wish to backup all data generated from views 
                    // then comment-out the "if (!$f_is_view)"-line above this {}-block 
                    // and remove the "LIMIT 0,0" part of the CREATE TEMPORARY TABLE query
                    
                    /**
                     * INSERT INTO
                     */
    
                    // Which fields to insert? (skip GENERATED columns as inserting values in those will generate errors)
                    $a_cols = array();
                    $result = mysqli_query($this->conn, "SHOW COLUMNS FROM `$table` WHERE `Extra` NOT LIKE '%GENERATED%'");
                    while ($row = mysqli_fetch_row($result)) $a_cols[]="`{$row[0]}`";
                    $cols = implode(',' , $a_cols);
                    if (strlen($cols)==0) $cols="*";
    
                    // Split table in batches in order to not exhaust system memory 
                    $row = mysqli_fetch_row(mysqli_query($this->conn, 'SELECT COUNT(*) FROM `'.$table.'`'));
                    $numRows = $row[0];
                    $numBatches = intval($numRows / $this->batchSize) + 1; // Number of while-loop calls to perform
    
                    for ($b = 1; $b <= $numBatches; $b++) {
                        
                        $query = "SELECT $cols FROM `$table` LIMIT " . ($b * $this->batchSize - $this->batchSize) . ',' . $this->batchSize;
                        $result = mysqli_query($this->conn, $query);
                        $realBatchSize = mysqli_num_rows ($result); // Last batch size can be different from $this->batchSize
                        $numFields = mysqli_num_fields($result);
    
                        if ($realBatchSize !== 0) {
                            $sql .= "\nINSERT INTO `$table` " . ($cols<>"*" ? "($cols)" : "") . ' VALUES ';
                            if ($this->batchSize > 1) {$sql .= "\n";}
                            for ($i = 0; $i < $numFields; $i++) {
                                $rowCount = 1;
                                while($row = mysqli_fetch_row($result)) {
                                    $sql.='(';
                                    for($j=0; $j<$numFields; $j++) {
                                        if (isset($row[$j])) {
                                            //$row[$j] = str_replace("\n","\\n",$row[$j]);
                                            //$row[$j] = str_replace("\r","\\r",$row[$j]);
                                            //$row[$j] = str_replace("\f","\\f",$row[$j]);
                                            //$row[$j] = str_replace("\t","\\t",$row[$j]);
                                            //$row[$j] = str_replace("\v","\\v",$row[$j]);
                                            //$row[$j] = str_replace("\a","\\a",$row[$j]);
                                            //$row[$j] = str_replace("\b","\\b",$row[$j]);
                                            $row[$j] = addslashes($row[$j]);
                                            //if ($row[$j] == 'true' or $row[$j] == 'false' or preg_match('/^-?[0-9]+$/', $row[$j]) or $row[$j] == 'NULL' or $row[$j] == 'null') {
                                            if ($row[$j] == 'true' or $row[$j] == 'false' or $row[$j] == 'NULL' or $row[$j] == 'null' or $row[$j] == '0' or $row[$j] == '1' or strval(intval($row[$j]))==$row[$j]) {
                                                $sql .= $row[$j];
                                            } else {
                                                $sql .= '"'.$row[$j].'"' ;
                                            }
                                        } else {
                                            $sql.= 'NULL';
                                        }
        
                                        if ($j < ($numFields-1)) {
                                            $sql .= ',';
                                        }
                                    }
        
                                    if ($rowCount == $realBatchSize) {
                                        $rowCount = 0;
                                        $sql.= ");"; //close the insert statement
                                    } else {
                                        $sql.= "),\n"; //close the row
                                    }
        
                                    $rowCount++;
                                }
                            }
        
                            $this->saveFile($sql);
                            $sql = '';
                        }
                    }
    
                    /**
                     * ALTER TABLE to recreate VIRTUAL COLUMNS // see under CREATE TABLE
                     */
                    
                    // restore GENERATED COLUMNs  -  Step 2
                    if (isset($altertable) && strlen($altertable)>0) {
                        $sql .= "\n".$altertable."\n\n";
                    }
                    
                    /**
                     * CREATE TRIGGER
                     */
    
                    // Check if there are some TRIGGERS associated to the table
                    $query = "SHOW TRIGGERS WHERE `Table`='$table'";
                    $result = mysqli_query ($this->conn, $query);
                    if ($result) {
                        $triggers = array();
                        
                        if (mysqli_num_rows($result)>0) $sql .= "\n\n --  TRIGGERS for table `$table`\n";
                        
                        while ($trigger = mysqli_fetch_row ($result)) {
                            $triggers[] = $trigger[0];
                        }
                        
                        // Iterate through triggers of the table
                        foreach ( $triggers as $trigger ) {
                            $query= 'SHOW CREATE TRIGGER `' . $trigger . '`';
                            $result = mysqli_fetch_array (mysqli_query ($this->conn, $query));
                            $sql.= "\nDROP TRIGGER IF EXISTS `" . $trigger . "`;\n\n";
                            $sql.= "DELIMITER $$\n" . $result[2] . "$$\nDELIMITER ;\n";
                        }
    
                        $sql.= "\n";
    
                        $this->saveFile($sql);
                        $sql = '';
                    }
                }

                if ($errno==0) $this->obfPrint('OK');
            }
            $sql.= "\n";
            
            /**
             * Procedures to export
             */
             
            if($procedures == '*') {
                $this->obfPrint("Backing up procedures");
                $procedures = array();
                $result = mysqli_query($this->conn, 'SHOW PROCEDURE STATUS');
                
                if (mysqli_num_rows($result)>0) $sql .= "/**/\n --  PROCEDURES\n";
                
                while($row = mysqli_fetch_row($result)) {
                    $procedures[] = $row[1];
                }

                /**
                 * Iterate procedures
                 */
                foreach($procedures as $procedure) {
                    $this->obfPrint("Backing up `".$procedure."` procedure..".str_repeat('.', max(47-strlen($procedure),0)), 0, 0);
    
                    /**
                     * CREATE PROCEDURE
                     */
                    $sql .= "/**/\n\n";
                    $sql .= 'DROP PROCEDURE IF EXISTS `'.$procedure."`;\n\n";
                    $row = mysqli_fetch_row(mysqli_query($this->conn, 'SHOW CREATE PROCEDURE `'.$procedure.'`'));
                    $sql .= "DELIMITER $$\n" .$row[2]."$$\nDELIMITER ;\n\n\n";
    
                    $this->obfPrint('OK');
                }
    
            }
            
            /**
             * Functions to export
             */
             
            if($functions == '*') {
                $this->obfPrint("Backing up functions");
                $functions = array();
                $result = mysqli_query($this->conn, 'SHOW FUNCTION STATUS');
                if (mysqli_num_rows($result)>0) $sql .= "/**/\n --  FUNCTIONS\n";
                while($row = mysqli_fetch_row($result)) {
                    $functions[] = $row[1];
                }

                /**
                 * Iterate functions
                 */
                foreach($functions as $function) {
                    $this->obfPrint("Backing up `".$function."` function..".str_repeat('.', max(48-strlen($function),0)), 0, 0);
    
                    /**
                     * CREATE FUNCTION
                     */
                    $sql .= "/**/\n\n";
                    $sql .= 'DROP FUNCTION IF EXISTS `'.$function."`;\n\n";
                    $row = mysqli_fetch_row(mysqli_query($this->conn, 'SHOW CREATE FUNCTION `'.$function.'`'));
                    $sql .= "DELIMITER $$\n" .$row[2]."$$\nDELIMITER ;\n\n\n";
    
                    $this->obfPrint('OK');
                }
    
            }
            
            /**
             * Views to export
             */
             
            if($views == '*') {
                $this->obfPrint("Backing up views");
                $views = array();
                $result = mysqli_query($this->conn, 'SELECT TABLE_SCHEMA, TABLE_NAME FROM information_schema.tables WHERE TABLE_TYPE LIKE "VIEW";');
                if (mysqli_num_rows($result)>0) $sql .= "/**/\n --  VIEWS\n";
                while($row = mysqli_fetch_row($result)) {
                    $views[] = $row[1];
                }

                /**
                 * Iterate views
                 */
                foreach($views as $view) {
                    $sql .= "/**/\n\n";
                    $this->obfPrint("Backing up `".$view."` view..".str_repeat('.', max(52-strlen($view),0)), 0, 0);
                    
                    $errno = 0;
                    mysqli_query($this->conn, "SHOW COLUMNS FROM `$view`"); // test view, this will throw error if view contains illegal definition
                    $errno = intval($this->conn->errno);
                    if ($errno) {
                        header('X-PHP-Response-Code: 424', true, 424);
                        $this->obfPrint("Error $errno -> illegal VIEW definition commented-out in backup!");
                        if (intval($GLOBALS['f_silent'])>0) {
                            echo "<pre>VIEWs: Illegal view definition in `$table` in database {$this->dbName} commented-out in backup! (error $errno) </pre>\n";
                        }
                    }                     
    
                    /**
                     * CREATE VIEW
                     */
                    $sql .= ($errno!=0 ? "-- ILLEGAL VIEW DEFINITION ???\n/*\n" : "");
                    $sql .= "DROP TABLE IF EXISTS `$view`;\n";
                    $sql .= "DROP VIEW IF EXISTS `$view`;\n\n";
                    $row = mysqli_fetch_row(mysqli_query($this->conn, 'SHOW CREATE VIEW `'.$view.'`'));
                    $sql .= $row[1] . ';' . ($errno!=0 ? "\n*/" : "") . "\n\n";
    
                    if ($errno==0) $this->obfPrint('OK');
                }
    
            }
            
            /**
             * Re-enable foreign key checks 
             */
            if ($this->disableForeignKeyChecks === true) {
                $sql .= "/**/\n\nSET foreign_key_checks = 1;\n";
            }

            $this->saveFile($sql);

            if ($this->gzipBackupFile) {
                $this->gzipBackupFile();
            } else {
                $this->obfPrint('Backup file succesfully saved to ' . $this->backupDir.'/'.$this->backupFile, 1, 0);
            }
        } catch (Exception $e) {
            print_r($e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Save SQL to file
     * @param string $sql
     */
    protected function saveFile(&$sql) {
        if (!$sql) return false;

        try {

            if (!file_exists($this->backupDir)) {
                mkdir($this->backupDir, 0777, true);
            }

            file_put_contents($this->backupDir.'/'.$this->backupFile, $sql, FILE_APPEND | LOCK_EX);

        } catch (Exception $e) {
            print_r($e->getMessage());
            return false;
        }

        return true;
    }

    /*
     * Gzip backup file
     *
     * @param integer $level GZIP compression level (default: 9)
     * @return string New filename (with .gz appended) if success, or false if operation fails
     */
    protected function gzipBackupFile($level = 9) {
        if (!$this->gzipBackupFile) {
            return true;
        }

        $source = $this->backupDir . '/' . $this->backupFile;
        $dest =  $source . '.gz';

        $this->obfPrint('Gzipping backup file to ' . $dest . '... ', 1, 0);

        $mode = 'wb' . $level;
        if ($fpOut = gzopen($dest, $mode)) {
            if ($fpIn = fopen($source,'rb')) {
                while (!feof($fpIn)) {
                    gzwrite($fpOut, fread($fpIn, 1024 * 256));
                }
                fclose($fpIn);
            } else {
                return false;
            }
            gzclose($fpOut);
            if(!unlink($source)) {
                return false;
            }
        } else {
            return false;
        }
        
        $this->obfPrint('OK');
        return $dest;
    }

    /**
     * Prints message forcing output buffer flush
     *
     */
    public function obfPrint ($msg = '', $lineBreaksBefore = 0, $lineBreaksAfter = 1) {
        if (!$msg) {
            return false;
        }

        if ($msg != 'OK' and $msg != 'KO') {
            $msg = date("Y-m-d H:i:s") . ' - ' . $msg;
        }
        $output = '';

        if (php_sapi_name() != "cli") {
            $lineBreak = "<br />";
        } else {
            $lineBreak = "\r\n";
        }

        if ($lineBreaksBefore > 0) {
            for ($i = 1; $i <= $lineBreaksBefore; $i++) {
                $output .= $lineBreak;
            }                
        }

        $output .= $msg;

        if ($lineBreaksAfter > 0) {
            for ($i = 1; $i <= $lineBreaksAfter; $i++) {
                $output .= $lineBreak;
            }                
        }


        // Save output for later use
        $this->output .= str_replace('<br />', "\r\n", $output);
        
        /*
        GLOBAL $f_silent;
        if(!$f_silent) echo $output;
        
        if (php_sapi_name() != "cli") {
            if( ob_get_level() > 0 ) {
                ob_flush();
            }
        }

        $this->output .= " ";

        flush();
        */
    }

    /**
     * Returns full execution output
     *
     */
    public function getOutput() {
        return $this->output;
    }
}

function Backup_Db ($DB_USER, $DB_PASSWORD, $DB_NAME, $DB_HOST, $BACKUP_DIR = '.', $TABLES = '*', $CHARSET = 'UTF-8') {
    GLOBAL $f_silent, $output;

    /**
     * Instantiate Backup_Database and perform backup
     */

    $backupDatabase = new Backup_Database($DB_HOST, $DB_USER, $DB_PASSWORD, $DB_NAME, $CHARSET);
    $backupDatabase->obfPrint('Backup database ' . $DB_NAME, 1, 1);
    $result = $backupDatabase->backupTables($TABLES, $BACKUP_DIR) ? 'OK' : 'KO';
    $backupDatabase->obfPrint('Backup result: ' . $result, 1);
    $backupDatabase->obfPrint('-- --- --/-/-- --- -- -   ', 1,1);
    
    // Use $output variable for further processing, for example to send it by email
    $output = $backupDatabase->getOutput();

    if (!$f_silent) {
        if (php_sapi_name() != "cli") echo '<PRE>';
        echo $output;
        if (php_sapi_name() != "cli") echo '</PRE>';
    }
    
    return $output;
} // end function Backup_Db
