<?php

/**
 * TODO: make a real is writeable test in test modus
 * TODO: make ftp client
 * TODO: follow symbolic links
 * TODO: rework/improve sftp:exec
 * TODO: ssh user on shared server must not have full path in all action in target path. define xml node to use target root path or not
 * TODO: copy file must be a stream write procedure since copy/fopen are asyncron operations and there is no way of determining the eof event to set chmod after file has been copied
 */

/**
 * @desc
 * linux/unix directory staging/sync script for php cli
 *
 * this script will copy contents of a source directory to a target directory as defined in a config xml file emulating a (s)ftp client.
 * the script will only run in cli modus from shell/command line and can be called like:
 *
 * - bash:~$ php -f /path/to/syncd.php
 *
 * in order for the script to run 2 mandatory parameter/argument must be passed in the shell/command line, e.g.:
 *
 * - bash:~$ php -f /path/to/syncd.php "config.xml"
 * - bash:~$ php -f /path/to/syncd.php "/var/www/tmp/config.xml"
 * - bash:~$ php -f /path/to/syncd.php "/var/www/tmp/config.xml" "live"
 *
 * the first mandatory argument expects the path to the config xml file. path must be absolute or filename only if config.xml resides in same
 * directory as the script.
 * the second mandatory argument expects the run modus as explained here:
 *
 * - "test"(default)  = simulates sync testing the basic requirements for a successful sync echoing everything in the shell
 * - "logged" = does the same as "test" with logging everything to a log report file
 * - "live" = executes the jobs defined in the config xml and also will write the report log file
 *
 * NOTE: the directory in which this script resides must be writeable in order to write the log report
 * NOTE: the script currently supports only sftp with ssh2 and normal user/pass authentification!
 *
 * The following displays are complete config xml file:
 *
<?xml version="1.0" encoding="utf-8"?>
<root>
    <config>
        (<target>
            <host>xx.xx.xx.xx</host>
            <port>22</port>
            <user>root</user>
            <pass></pass>
        </target>)
        <sourcebase></sourcebase>
        <targetbase></targetbase>
        <compare>date</compare>
        <resync>1</resync>
        <skip>*.svn,*.git,*.log</skip>
        <modified_since>10.11.09 15:20:21</modified_since>
        <chmod>0755</chmod>
        <chown>www-data</chown>
        <chgrp>www-data</chgrp>
    </config>
    <jobs>
        <job compare="size" resync="1">
            <target><![CDATA[/backup]]></target>
            <source><![CDATA[/var/www/website/private]]></source>
            <excludes>
                <exclude><![CDATA[/var/www/website/private/tpls/*]]></exclude>
                <exclude><![CDATA[/backup]]></exclude>
                <exclude><![CDATA[/config/config.inc.php]]></exclude>
                <exclude><![CDATA[functions.php]]></exclude>
            </excludes>
        </job>
    </jobs>
</root>
 *
 * The config parameters are the following:
 *
 * NOTE: Use target node only when dealing with (S)FTP sync else omit target node and child nodes!!!
 *
 * config.target (optional!)
 *
 * 1) config.target.host (mandatory)
 * expects the host name or ip for the remote target
 *
 * 2) config.target.port (mandatory)
 * expects the port (crucial to decide which protocol to use)
 *
 * 3) config.target.user (mandatory)
 * expects the user for normal user/pass authentification
 *
 * 4) config.target.pass (optional)
 * expects the ssh user password (if not set will asked for in shell)
 *
 * 5) config.sourcebase (optional)
 * expects the source root/base path. if set the job source path must be relative because basepath + jobpath
 *
 * 6) config.targetbase (optional)
 * expects the target root/base path. if set the job target path must be relative because basepath + jobpath
 * the targetbase path also set the current working directory on the target server. if not set the app will look for the cwd
 * automatically using the unix pwd command.
 * NOTE: on shared hosts the pwd command still will return the correct path from root but ftp/sftp can only write to relative path.
 * in this case the targetbase path should be set like "/" forcing the cwd to the path of the logged in user (relative)
 *
 * 7) config.compare (optional)
 * if set expects a compare modus (size|date) which will compare files and sync only if rule does not apply
 *
 * 8) config.resync (optional)
 * expects a value (0 = off|1 = on) if the process should also delete orphaned files on target remote server
 *
 * 9) config.skip (optional)
 * expects optional skip rules which is a comma separated list of file extensions
 *
 * 10) config.modified_since (optional)
 * if set syncs only files which are newer then modification date
 *
 * 11) config.chmod (optional)
 * expects a chmod value to set to remote file once syncd to remote server
 *
 * 12) config.chown (optional)
 * expects a username as string to set after copy of file/dir has been successful. the username will be tested before sync to check if username does exist on target server.
 * the new username will only be set if the to be copied source file/dir does have a different user as owner
 *
 * 13) config.chgrp (optional)
 * expects a group name as string to set after copy of file/dir has been successful. the group will be tested before sync to check if group does exist on target server.
 * the new group name will only be set if the to be copied source file/dir does have a different user as owner
 *
 * The following parameters can be defined in job node as attribute to overwrite global values:
 *
 * 1) compare
 * 2) resync
 * 3) skip
 * 4) chmod
 * 5) chown
 * 6) chgrp
 * 7) modified_since
 *
 * (see descriptions for global parameters)
 *
 * Each job can be defined with the following parameter:
 *
 * 1) job.source (mandatory)
 * defines the source path to sync files from -> to remote target path. The path must be absolute if source basepath is not set.
 *
 * 2) job.target (mandatory)
 * defines the target path on remote server where to sync files from source -> target. The path must be absolute if target basepath is not set.
 *
 * 3) excludes (optional)
 * defines the exclude rules which can be:
 * - absolute path in source
 * - relative path to job source dir
 * - filename + extension (will exclude all files of the same name in all directories of job source path!)
 * - path + filename # extension will exclude a specific file from job source path (defined relative or absolute)
 *
 * @author setcookie <set@cooki.me>
 * @link set.cooki.me
 * @copyright Copyright &copy; 2011-2012 setcookie
 * @license http://www.gnu.org/copyleft/gpl.html
 * @package syncd
 * @version 0.0.4
 * @desc base class for sync
 * @throws Exception
 */
class Syncd
{
    const MODE_LIVE             = "live";
    const MODE_TEST             = "test";
    const MODE_TEST_LOGGED      = "logged";
    const LOG_NOTICE            = "notice";
    const LOG_SUCCESS           = "success";
    const LOG_ERROR             = "error";
    protected                   $_xml = null;
    protected                   $_mode = null;
    protected                   $_xmlArray = null;
    protected                   $_conn = null;
    protected                   $_log = array();
    protected                   $_err = 0;
    protected                   $_logFile = null;
    protected static            $_instance = null;


    /**
     * @desc validates parameter and checks for valid environment
     * @throws Exception
     * @param string|null $xml expects absolute or relative path to config xml
     * @param string $mode expects the run mode
     */
    public function __construct($xml = null, $mode = self::MODE_TEST)
    {
        if($xml !== null)
        {
            @set_time_limit(0);
            @error_reporting(E_ALL);
            @ini_set("display_errors", 0);
            @ini_set('memory_limit', '512M');

            if($mode === null)
            {
                $this->_mode = $mode = self::MODE_TEST;
            }else{
                $this->_mode = strtolower(trim($mode));
            }
            if(strtolower(trim(php_sapi_name())) !== 'cli')
            {
                echo "script can only be called from command line (cli)";
                die();
                exit(0);
            }
            if(!defined("DIRECTORY_SEPARATOR"))
            {
                define('DIRECTORY_SEPARATOR', ((isset($_ENV["OS"]) && stristr('win',$_ENV["OS"]) !== false) ? '\\' : '/'));
            }
            if(!(bool)ini_get('allow_url_fopen'))
            {
                throw new Exception("system does not support open (s)ftp protocol wrapper");
            }
            if(!class_exists('RecursiveDirectoryIterator', false))
            {
                throw new Exception("system does not support recursive iterators");
            }
            if(stristr($xml, DIRECTORY_SEPARATOR) === false)
            {
                $this->_xml = $xml = rtrim(realpath(dirname(__FILE__)), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $xml;
            }else{
                $this->_xml = $xml;
            }

            $this->_logFile = realpath(dirname(__FILE__)) . DIRECTORY_SEPARATOR . basename($this->_xml, ".xml") . "-". strftime("%d%m%y-%H%I%S", time()) . ".report.log";

            if(($dom = DOMDocument::load($this->_xml, LIBXML_NOBLANKS | LIBXML_NOCDATA)) !== false)
            {
                $this->_xmlArray = array_shift($this->xmlToArray($dom));
                $this->init();
                $this->exec();
            }else{
                throw new Exception("xml config file: $xml not found or invalid");
            }
        }
    }


    /**
     * @desc singleton run method to shortcut run syncd
     * @static
     * @param string|null $xml expects absolute or relative path to config xml
     * @param string $mode expects the run mode
     * @return null|Syncd
     */
    public static function run($xml = null, $mode = self::MODE_TEST)
    {
        if(self::$_instance === null)
        {
            self::$_instance = new self($xml, $mode);
        }
        return self::$_instance;
    }


    /**
     * @desc init method validates xml config and inits remote connection
     * @throws Exception
     * @return void
     */
    protected function init()
    {
        if($this->_xmlArray !== null)
        {
            $xml =& $this->_xmlArray['config'];

            if(isset($xml['target']))
            {
                if(!isset($xml['target']['host']))
                {
                    throw new Exception("config file must define config.target.host");
                }
                if(!isset($xml['target']['port']))
                {
                    throw new Exception("config file must define config.target.port");
                }
                if(!isset($xml['target']['user']))
                {
                    throw new Exception("config file must define config.target.user");
                }
                if(!isset($xml['target']['pass']) || empty($xml['target']['pass']))
                {
                    echo "Password for target: ";
                    @system('stty -echo');
                    $pass = trim(fgets(STDIN));
                    if(!empty($pass))
                    {
                        $xml['target']['pass'] = $pass;
                    }else{
                        die("Please run script again an enter correct password");
                        exit(0);
                    }
                }
            }
            $tmp = rtrim(realpath(dirname($this->_xml)), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if((bool)@file_put_contents($tmp . ".tmp", " "))
            {
                @unlink($tmp . ".tmp");
            }else{
                throw new Exception("config dir: $tmp must be writeable in order to write log report");
            }

            if(isset($xml['target']))
            {
                $class = ((int)$xml['target']['port'] === 21 || strtolower(trim($xml['target']['port'])) === "ftp") ? "Ftp" : "Sftp";
                $this->_conn = new $class($this);
                $this->_conn->init
                (
                    $xml['target']['host'],
                    $xml['target']['port'],
                    $xml['target']['user'],
                    $xml['target']['pass']
                );
                $this->_conn->connect();
            }else{
                $this->_conn = new Fs($this);
            }
        }
    }


    /**
     * @desc executes the sync with all config settings
     * @throws Exception
     * @return void
     */
    protected function exec()
    {
        $tmp = array();
        $xml =& $this->_xmlArray['jobs'];
        
        if(isset($xml['job']))
        {
            $xml['job'] = array($xml['job']);
        }
        if(!isset($xml['job'][0]))
        {
            throw new Exception("there is nothing to sync");
        }
        
        $this->log("starting sync", self::LOG_NOTICE);

        $j = 0;
        foreach($xml['job'] as $k => $v)
        {
            $this->log(".executing job: $j", self::LOG_NOTICE);
            $resync = (bool)$this->get("config.resync", false);
            if($this->is("jobs.job.$j.#attrs.resync"))
            {
                $resync = (bool)$this->get("jobs.job.$j.#attrs.resync", false);
            }
            $skip = trim($this->get("config.skip"));
            if($this->is("jobs.job.$j.#attrs.skip"))
            {
                $skip = trim($this->get("jobs.job.$j.#attrs.skip"));
            }
            $chmod = trim($this->get("config.chmod"));
            if($this->is("jobs.job.$j.#attrs.chmod"))
            {
                $chmod = trim($this->get("jobs.job.$j.#attrs.chmod"));
            }
            $chown = trim($this->get("config.chown"));
            if($this->is("jobs.job.$j.#attrs.chown"))
            {
                $chown = trim($this->get("jobs.job.$j.#attrs.chown"));
            }
            $chgrp = trim($this->get("config.chgrp"));
            if($this->is("jobs.job.$j.#attrs.chgrp"))
            {
                $chgrp = trim($this->get("jobs.job.$j.#attrs.chgrp"));
            }
            $compare = strtolower($this->get("config.compare"));
            if($this->is("jobs.job.$j.#attrs.compare"))
            {
                $compare = strtolower($this->get("jobs.job.$j.#attrs.compare"));
            }
            $modified_since = trim($this->get("config.modified_since"));
            if($this->is("jobs.job.$j.#attrs.modified_since"))
            {
                $modified_since = (bool)$this->get("jobs.job.$j.#attrs.modified_since");
            }
            $time_offset = $this->get("config.time_offset", 0);
            $exclude = null;
            if($this->is("jobs.job.$j.excludes.exclude"))
            {
                $exclude = $this->get("jobs.job.$j.excludes.exclude");
                if(!is_array($exclude))
                {
                    $exclude = array($exclude);
                }
            }

            $this->log("..using compare mode: $compare", self::LOG_NOTICE);
            $this->log("..using resync option: " . (($resync) ? "yes" : "no"), self::LOG_NOTICE);
            $this->log("..using skip rules: $skip", self::LOG_NOTICE);
            $this->log("..using chmod value: $chmod", self::LOG_NOTICE);
            $this->log("..using chown value: $chown", self::LOG_NOTICE);
            $this->log("..using chgrp value: $chgrp", self::LOG_NOTICE);
            $this->log("..using modified since value: ".((!empty($modified_since)) ? $modified_since : ""), self::LOG_NOTICE);
            $this->log("..using time offset value: ".$time_offset, self::LOG_NOTICE);

            //testing for user
            if(!empty($chown))
            {
                if(!$this->_conn->isOwn($chown))
                {
                    $this->log("....user: $chown does not exist on target server", self::LOG_ERROR, true);
                }
            }

            //testing for group
            if(!empty($chgrp))
            {
                if(!$this->_conn->isGrp($chgrp))
                {
                    $this->log("....group: $chgrp does not exist on target server", self::LOG_ERROR, true);
                }
            }

            //testing source dir
            $source = rtrim($this->get("config.sourcebase"), DIRECTORY_SEPARATOR."*") . DIRECTORY_SEPARATOR . trim($v['source'], DIRECTORY_SEPARATOR."*") . DIRECTORY_SEPARATOR;
            $this->log("...validating source dir: $source", self::LOG_NOTICE);
            if(!is_dir($source) && is_readable($source))
            {
                $this->log("....dir: $source FAILED (not found or not readable)", self::LOG_ERROR, true);
            }

            //testing target dir and setting current working directory
            if($this->is("config.targetbase"))
            {
                $cwd = $this->get("config.targetbase");
                if($cwd === DIRECTORY_SEPARATOR)
                {
                    $this->_conn->setCwd("/");
                }else{
                   $this->_conn->setCwd(DIRECTORY_SEPARATOR . trim($this->get("config.targetbase"), DIRECTORY_SEPARATOR."*") .  DIRECTORY_SEPARATOR);
                }
            }else{
                $this->_conn->setCwd();
            }
            $target = trim($v['target'], DIRECTORY_SEPARATOR."*") . DIRECTORY_SEPARATOR;
            $this->log("...validating target dir: $target", self::LOG_NOTICE);

            if(!$this->_conn->testDir($target))
            {
                $this->log("....dir: $target FAILED (not found or not writeable)", self::LOG_ERROR, true);
            }
            
            $skip = explode(",", str_replace(array(";", ".", "*"), array(",", "", ""), $skip));

            //prepare modified since value
            if(!empty($modified_since))
            {
                $modified_since = trim($modified_since);
                //assume timestamp
                if(is_numeric($modified_since) && (int)$modified_since == $modified_since){
                    $modified_since = (int)$modified_since;
                //assume date/time
                }else if((bool)preg_match("/^([\d]{1,2})(?:\-|\/|\.)([\d]{1,2})(?:\-|\/|\.)([\d]{2,4})\s+([\d]{2})(?:\:)([\d]{2})(?:\:)([\d]{2})$/i", $modified_since, $m)){
                    $modified_since = mktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[1], (int)$m[2], (int)$m[3]);
                //assume date
                }else if((bool)preg_match("/^([\d]{1,2})(?:\-|\/.)([\d]{1,2})(?:\-|\/.)([\d]{2,4})$/i", $modified_since, $m)){
                    $modified_since = mktime(0, 0, 0, (int)$m[1], (int)$m[2], (int)$m[3]);
                }else{
                    throw new Exception("modified since date: $modified_since is not a valid date/time");
                }
            }

            //prepare offset value
            if(!empty($time_offset))
            {
                if(is_numeric($time_offset)){
                    $time_offset = (int)$time_offset;
                }else{
                    throw new Exception("server time offset: $time_offset is not a valid value");
                }
            }

            //prepare exclude values
            if($exclude !== null)
            {
                foreach($exclude as &$ex)
                {
                    $ex = trim($ex, "*");
                    //the exclude rule is a absolute path to directory
                    if(@is_dir($ex)){
                        $ex = "#^" . addslashes(DIRECTORY_SEPARATOR . trim($ex, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) . ".*#i";
                    //the exclude rule is a relative path to directory
                    }else if(@is_dir($source . ltrim($ex, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)){
                        $ex = "#^" . addslashes($source . trim($ex, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR). ".*#i";
                    //the exclude rule is a file with directory address
                    }else if(stristr($ex, DIRECTORY_SEPARATOR) !== false){
                        $ex = "#".addslashes($source . ltrim($ex, DIRECTORY_SEPARATOR))."$#i";
                    //the exclude rule is a file only
                    }else{
                        $ex = "#(.*)".addslashes($ex)."$#i";
                    }
                }
                unset($ex);
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::SELF_FIRST);

            //iterate to source directories and sync
            foreach($iterator as $i)
            {
                $source_absolute_path   = ($i->isDir()) ? rtrim($i->__toString(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : $i->__toString();
                $source_relative_path   = DIRECTORY_SEPARATOR . trim(str_replace($source, "", $source_absolute_path), DIRECTORY_SEPARATOR);
                $target_absolute_path   = $target . trim($source_relative_path, DIRECTORY_SEPARATOR);
                $tmp[]                  = $source_relative_path;

                //get permissions and convert accordingly
                if(($source_permission = @fileperms($source_absolute_path)) !== false)
                {
                    if(!empty($chmod))
                    {
                        $source_permission_num = octdec((string)(int)$chmod);
                        $source_permission_str = str_pad(trim($chmod), 4, 0, STR_PAD_LEFT);
                    }else{
                        $source_permission_num = $this->getMod($source_permission);
                        $source_permission_str = substr(decoct($source_permission), -4);
                    }
                }else{
                    $this->log("...file/dir: $source_absolute_path is skipped because unable to get file permission from source file/dir", self::LOG_ERROR);
                    continue 1;
                }

                //get chown user
                $owner = fileowner($source_absolute_path);
                if($owner !== false && ($owner = posix_getpwuid((int)$owner)) !== false)
                {
                    if(!empty($chown))
                    {
                        if(strtolower(trim($owner['name'])) === strtolower(trim((string)$chown)))
                        {
                            $chown = "";
                        }
                    }
                }else{
                    $this->log("...file/dir: $source_absolute_path is skipped because unable to get owner from source file/dir", self::LOG_ERROR);
                    continue 1;
                }

                //get chgrp group
                $group = filegroup($source_absolute_path);
                if($group !== false && ($group = posix_getgrgid((int)$group)) !== false)
                {
                    if(!empty($chgrp))
                    {
                        if(strtolower(trim($group['name'])) === strtolower(trim((string)$chgrp)))
                        {
                            $chgrp = "";
                        }
                    }
                }else{
                    $this->log("...file/dir: $source_absolute_path is skipped because unable to get group from source file/dir", self::LOG_ERROR);
                    continue 1;
                }

                if($exclude !== null)
                {
                    foreach($exclude as $ex)
                    {
                        if((bool)preg_match($ex, $source_absolute_path))
                        {
                            $this->log("...file/dir: $source_absolute_path is skipped from sync since file/dir was found in exclude rule: $ex", self::LOG_NOTICE);
                            continue 2;
                        }
                    }
                }

                if(!empty($modified_since) !== null && !$i->isDir() && ((int)$i->getMTime() + $time_offset) < $modified_since)
                {
                    $this->log("...file: $source_absolute_path is skipped from sync since file modification time: ".strftime("%m-%d-%y %H:%M:%S", $i->getMTime()) . (($time_offset > 0) ? "+ time offset" : "")." is < ".strftime("%m-%d-%y %H:%M:%S", $modified_since), self::LOG_NOTICE);
                    continue 1;
                }

                if(!$i->isDir())
                {
                    $ext = strtolower(str_replace(".", "", trim(substr($source_absolute_path, strrpos($source_absolute_path, ".") + 1))));
                    if(sizeof($skip) > 0 && in_array($ext, $skip))
                    {
                        $this->log("...file: $source_absolute_path is skipped from sync because of skip rule in place", self::LOG_NOTICE);
                        continue;
                    }
                    if($this->_conn->isFile($target_absolute_path) && !empty($compare))
                    {
                        if($compare === "time")
                        {
                            if((int)$i->getMTime() > $this->_conn->fileTime($target_absolute_path))
                            {
                                $this->log("...file: $source_absolute_path already exists on target server at: $target_absolute_path and will be overwritten since source file is newer", self::LOG_NOTICE);
                                if($this->_mode === self::MODE_LIVE)
                                {
                                    if($this->_conn->copy($source_absolute_path, $target_absolute_path, $source_permission_num))
                                    {
                                        $this->log("....file: $source_absolute_path OK", self::LOG_SUCCESS);
                                    }else{
                                        $this->log("....file: $source_absolute_path FAILED", self::LOG_ERROR);
                                    }
                                    if(!empty($chown))
                                    {
                                        $this->_conn->chOwn($target_absolute_path, $chown);
                                    }
                                    if(!empty($chgrp))
                                    {
                                        $this->_conn->chGrp($target_absolute_path, $chgrp);
                                    }
                                }
                            }else{
                                $this->log("...file: $source_absolute_path already exists on target server at: $target_absolute_path and will NOT be overwritten since target file is newer", self::LOG_NOTICE);
                            }
                        }else if($compare === "size"){
                            if((int)$i->getSize() !== $this->_conn->fileSize($target_absolute_path))
                            {
                                $this->log("...file: $source_absolute_path already exists on target server at: $target_absolute_path and will be overwritten since file size has changed", self::LOG_NOTICE);
                                if($this->_mode === self::MODE_LIVE)
                                {
                                    if($this->_conn->copy($source_absolute_path, $target_absolute_path, $source_permission_num))
                                    {
                                        $this->log("....file: $source_absolute_path OK", self::LOG_SUCCESS);
                                    }else{
                                        $this->log("....file: $source_absolute_path FAILED", self::LOG_ERROR);
                                    }
                                    if(!empty($chown))
                                    {
                                        $this->_conn->chOwn($target_absolute_path, $chown);
                                    }
                                    if(!empty($chgrp))
                                    {
                                        $this->_conn->chGrp($target_absolute_path, $chgrp);
                                    }
                                }
                            }else{
                                $this->log("...file: $source_absolute_path already exists on target server at: $target_absolute_path and will NOT be overwritten since file size has NOT changed", self::LOG_NOTICE);
                            }
                        }
                    }else{
                        $this->log("...file: $source_absolute_path does not exists on target server and will be copied", self::LOG_NOTICE);
                        if($this->_mode === self::MODE_LIVE)
                        {
                            if($this->_conn->copy($source_absolute_path, $target_absolute_path, $source_permission_num))
                            {
                                $this->log("....file: $source_absolute_path OK", self::LOG_SUCCESS);
                            }else{
                                $this->log("....file: $source_absolute_path FAILED", self::LOG_ERROR);
                            }
                            if(!empty($chown))
                            {
                                $this->_conn->chOwn($target_absolute_path, $chown);
                            }
                            if(!empty($chgrp))
                            {
                                $this->_conn->chGrp($target_absolute_path, $chgrp);
                            }
                        }
                    }
                }else{
                    if(!$this->_conn->isDir($target_absolute_path))
                    {
                        $this->log("...dir: $target_absolute_path does not exists on target server and will be created", self::LOG_NOTICE);
                        if($this->_mode === self::MODE_LIVE)
                        {
                            if($this->_conn->mkDir($target_absolute_path, $source_permission_num))
                            {
                                $this->log("....dir: $source_absolute_path OK", self::LOG_SUCCESS);
                            }else{
                                $this->log("....dir: $source_absolute_path FAILED", self::LOG_ERROR);
                            }
                            if(!empty($chown))
                            {
                                $this->_conn->chOwn($target_absolute_path, $chown);
                            }
                            if(!empty($chgrp))
                            {
                                $this->_conn->chGrp($target_absolute_path, $chgrp);
                            }
                        }
                    }
                }
                @clearstatcache();
            }

            if($resync)
            {
                $this->log("..searching for orphaned files/dirs", self::LOG_NOTICE);
                if(($iterator = $this->_conn->lsDir($target)) !== false)
                {
                    foreach($iterator as $i)
                    {
                        $_i = DIRECTORY_SEPARATOR . ltrim(str_replace($target, "", $i), DIRECTORY_SEPARATOR);
                        if(!in_array($_i, $tmp))
                        {
                            $this->log("...file/dir: $i has been detected as orphaned and will be deleted", self::LOG_NOTICE);
                            if($this->_mode === self::MODE_LIVE)
                            {
                                $type = $this->_conn->fileType($i);
                                if($type !== false)
                                {
                                    if($type === "dir")
                                    {
                                        if($this->_conn->rmDir($i))
                                        {
                                            $this->log("....dir: $i deleted OK", self::LOG_SUCCESS);
                                        }else{
                                            $this->log("....dir: $i deleted FAILED", self::LOG_ERROR);
                                        }
                                    }else{
                                        if($this->_conn->rmFile($i))
                                        {
                                            $this->log("....file: $i deleted OK", self::LOG_SUCCESS);
                                        }else{
                                            $this->log("....file: $i deleted FAILED", self::LOG_ERROR);
                                        }
                                    }
                                }else{
                                    $this->log("....file/dir: $i type could not be identified", self::LOG_ERROR);
                                }
                            }
                        }
                    }
                }else{
                    $this->log("..dir: $target could not be opened for orphaned files lookup", self::LOG_ERROR);
                }
            }
            $this->log(".job: $j completed", self::LOG_NOTICE);
            @clearstatcache();
            $j++;
        }
        if($this->_conn->hasError())
        {
            $this->log("sync complete with errors: " .$this->_conn->countError(), self::LOG_NOTICE);
            if($this->_mode === self::MODE_TEST)
            {
                $this->log("please run again in test logged (test_logged) modi for extended error logging", self::LOG_NOTICE);
            }else{
                $this->log("please see log report: ".$this->_logFile." for extended errors", self::LOG_NOTICE);
            }
        }else{
            $this->log("sync complete", self::LOG_NOTICE);
        }
    }


    /**
     * @desc gets value from config file
     * @param null|string $key expects string key in form of node.node.node
     * @param string $default optional return value if key was not found
     * @return mixed|null|string
     */
    protected function get($key = null, $default = "")
    {
        $val = null;
        
        if(($val = $this->is($key)) !== false)
        {
            return $val;
        }
        return $default;
    }


    /**
     * @desc checks whether a key (config parameter) is set in config xml or not
     * @param null|string $key expects string key in form of node.node.node
     * @return bool
     */
    protected function is($key = null)
    {
        if($key !== null)
        {
            $keys = explode(".", strtolower(trim($key)));
            $xml =& $this->_xmlArray;
            $res = eval("return \$xml['".implode("']['", $keys)."'];");
            if($res !== null && !empty($res))
            {
                return $res;
            }
        }
        return false;
    }


    /**
     * @desc recieved log messages and decides to output to console and/or log
     * @param null|array|string $mixed expects log entry
     * @param string $level optional expects log level
     * @param boolean $quit optional whether to quit sync or not
     * @return void
     */
    public function log($mixed = null, $level = self::LOG_NOTICE, $quit = false)
    {
        if($mixed !== null)
        {
            if($level === self::LOG_ERROR)
            {
                $this->_err += (is_array($mixed)) ? sizeof($mixed) : 1;
            }
            if($this->_mode === self::MODE_TEST_LOGGED || $this->_mode === self::MODE_LIVE)
            {
                if(is_array($mixed))
                {
                    foreach($mixed as $k => $v)
                    {
                        $this->_log[] = array($v, $level);
                    }
                }else{
                    $this->_log[] = array($mixed, $level);
                }
            }
            $this->console($mixed, $level);
            if((bool)$quit)
            {
                exit(0);
            }
        }
    }


    /**
     * @param null|array|string $mixed expects log entry
     * @param string $level optional expects log level
     * @return void
     */
    protected function console($mixed = null, $level = null)
    {
        if($mixed !== null)
        {
            if(!is_array($mixed))
            {
                $mixed = array($mixed);
            }

            if($level !== null)
            {
                $level = strtolower(trim($level));
                switch($level)
                {
                    case self::LOG_ERROR:
                        foreach($mixed as $m)
                        {
                            echo "\033[01;31m".trim($m)."\033[0m\n";
                        }
                        break;
                    case self::LOG_SUCCESS:
                        foreach($mixed as $m)
                        {
                            echo "\033[01;34m".trim($m)."\033[0m\n";
                        }
                        break;
                    default:
                        foreach($mixed as $m)
                        {
                            echo $m . "\n";
                        }
                }
            }else{
                foreach($mixed as $m)
                {
                    echo $m . "\n";
                }
            }
        }
    }


    /**
     * @desc translates DOM xml structure/object into array
     * @param DOMNode|null $node expects the root/child node to recursive transform childs into array
     * @return array
     */
    protected function xmlToArray(DOMNode $node = null)
    {
        $result = array();
        $group = array();
        $attrs = null;
        $children = null;

        if($node->hasAttributes())
        {
            $attrs = $node->attributes;
            foreach($attrs as $k => $v)
            {
                $result['#attrs'][$v->name] = $v->value;
            }
        }

        $children = $node->childNodes;

        if(!empty($children))
        {
            if((int)$children->length === 1)
            {
                $child = $children->item(0);

                if($child !== null && $child->nodeType === XML_TEXT_NODE)
                {
                    $result['#value'] = $child->nodeValue;
                    if(count($result) == 1)
                    {
                        return $result['#value'];
                    }else{
                        return $result;
                    }
                }
            }

            for($i = 0; $i < (int)$children->length; $i++)
            {
                $child = $children->item($i);

                if($child !== null)
                {
                    if(!isset($result[$child->nodeName]))
                    {
                        $result[$child->nodeName] = $this->xmlToArray($child);
                    }else{
                        if(!isset($group[$child->nodeName]))
                        {
                            $result[$child->nodeName] = array($result[$child->nodeName]);
                            $group[$child->nodeName] = 1;
                        }
                        $result[$child->nodeName][] = $this->xmlToArray($child);
                    }
                }
            }
        }
        return $result;
    }


    /**
     * @desc gets file permission from file
     * @static
     * @param null $file
     * @return bool|int
     */
    public static function getMod($mixed = null)
    {
        $perms = 0;

        if($mixed !== null)
        {
            $val = 0;

            if(!is_numeric($mixed))
            {
                $perms = (int)@fileperms($mixed);
            }else{
                $perms = (int)$mixed;
            }

            if($perms !== 0)
            {
                // Owner; User
                $val += (($perms & 0x0100) ? 0x0100 : 0x0000); //Read
                $val += (($perms & 0x0080) ? 0x0080 : 0x0000); //Write
                $val += (($perms & 0x0040) ? 0x0040 : 0x0000); //Execute
                // Group
                $val += (($perms & 0x0020) ? 0x0020 : 0x0000); //Read
                $val += (($perms & 0x0010) ? 0x0010 : 0x0000); //Write
                $val += (($perms & 0x0008) ? 0x0008 : 0x0000); //Execute
                // Global; World
                $val += (($perms & 0x0004) ? 0x0004 : 0x0000); //Read
                $val += (($perms & 0x0002) ? 0x0002 : 0x0000); //Write
                $val += (($perms & 0x0001) ? 0x0001 : 0x0000); //Execute
                // Misc
                $val += (($perms & 0x40000) ? 0x40000 : 0x0000); //temporary file (01000000)
                $val += (($perms & 0x80000) ? 0x80000 : 0x0000); //compressed file (02000000)
                $val += (($perms & 0x100000) ? 0x100000 : 0x0000); //sparse file (04000000)
                $val += (($perms & 0x0800) ? 0x0800 : 0x0000); //Hidden file (setuid bit) (04000)
                $val += (($perms & 0x0400) ? 0x0400 : 0x0000); //System file (setgid bit) (02000)
                $val += (($perms & 0x0200) ? 0x0200 : 0x0000); //Archive bit (sticky bit) (01000)
                return $val;
            }
        }
        return false;
    }


    /**
     * @desc class destructor writes log report if existent
     */
    public function __destruct()
    {
        if($this->_mode === self::MODE_TEST_LOGGED || $this->_mode === self::MODE_LIVE)
        {
            $txt = "Sync report for: ".$this->_xml." on: ". strftime("%d-%m-%Y %H:%I:%S", time())."\r\n";
            foreach($this->_log as $log)
            {
                $txt .= strtoupper($log[1]).": " . $log[0] . "\n";
            }
            if(!empty($txt))
            {
                @file_put_contents($this->_logFile, $txt);
            }
        }
    }
}

/**
* @author setcookie <set@cooki.me>
* @link set.cooki.me
* @copyright Copyright &copy; 2011-2012 setcookie
* @license http://www.gnu.org/copyleft/gpl.html
* @package syncd
* @since 0.0.1
* @desc abstract file transfer class serves as base class for all protocol dependend sub classes/adapters
*/
abstract class Ft
{
    /**
     * set with the connection resource handler depending on protocol implementation
     * @var resource $_conn
     */
    protected $_conn = null;

    /**
     * contains the host name/url/ip according to protocol
     * @var string $_host
     */
    protected $_host = null;

    /**
     * contains the port number according to protocol
     * @var int $_port
     */
    protected $_port = null;

    /**
     * contains the user name if authentication is done by user/pass
     * @var string $_user
     */
    protected $_user = null;

    /**
     * contains the password if authentication is done by user/pass
     * @var string $_pass
     */
    protected $_pass = null;

    /**
     * contains all errors in form of error pool
     * @var array $_err
     */
    protected $_err = array();

    /**
     * contains syncd class instance so protocol adapter can be embeded in workflow
     * @var null|Syncd $_syncd
     */
    protected $_syncd = null;

    /**
     * contains current working directory accoring to adapter
     * @var string $_cwd
     */
    protected $_cwd = "";


    /**
     * @desc initializes base class
     * @param null|Syncd $syncd expects the syncd instance as parent workflow container
     * @public
     */
    public function __construct(Syncd $syncd = null)
    {
        if($syncd !== null)
        {
            $this->_syncd = $syncd;
        }else{
            throw new Exception("syncd instance must be passed in first parameter");
        }
    }

    /**
     * @desc init default method setting connection settings only used in s(ftp) protocols
     * @param string $host (mandatory) expects the host name string
     * @param int $port (optional) expects the port to connect to host
     * @param string $user (mandatory) expects the user name for authentication
     * @param string $pass (mandatory) expects the password for authentication
     * @public
     * @return void
     */
    public function init($host = null, $port = 22, $user = null, $pass = null)
    {
        if($host !== null && $user !== null && $pass !== null)
        {
            $this->_host = trim((string)$host);
            $this->_port = (int)$port;
            $this->_user = trim((string)$user);
            $this->_pass = trim((string)$pass);
        }
    }

    /**
     * @desc return boolean value if error has occured or not
     * @public
     * @return bool
     */
    public function hasError()
    {
        return (sizeof($this->_err) > 0) ? true : false;
    }

    /**
     * @desc returns error pool containing all errors as array
     * @public
     * @return array
     */
    public function getError()
    {
        return $this->_err;
    }

    /**
     * @desc returns the error count - number of total errors
     * @public
     * @return int
     */
    public function countError()
    {
        return sizeof($this->_err);
    }

    /**
     * @desc log error to console via syncd instance passed in class constructor
     * @param string $err expects error string
     * @protected
     * @return void
     */
    protected function error($err = null)
    {
        if($err !== null)
        {
            $this->_err[] = $err;
            if($this->_syncd !== null)
            {
                $this->_syncd->log("...." . $err, Syncd::LOG_ERROR);
            }
        }
    }

    abstract public function connect();
    abstract public function testDir();
    abstract public function isDir();
    abstract public function isFile();
    abstract public function fileTime($file = null, $m = "m");
    abstract public function fileSize($file = null);
    abstract public function copy($source = null, $target = null, $mode = null);
    abstract public function mkDir($dir = null);
    abstract public function lsDir($dir = null);
    abstract public function rmDir($dir = null);
    abstract public function rmFile($file = null);
    abstract public function fileType($file = null);
    abstract public function setCwd($cwd = null);
    abstract public function chOwn($file = null, $user = null);
    abstract public function chGrp($file = null, $group = null);
    abstract public function isOwn($user = null);
    abstract public function isGrp($group = null);
}

/**
* @author setcookie <set@cooki.me>
* @link set.cooki.me
* @copyright Copyright &copy; 2011-2012 setcookie
* @license http://www.gnu.org/copyleft/gpl.html
* @package syncd
* @since 0.0.1
* @desc concrete implementation of ftp protocol
*/
class Ftp extends Ft
{
    public function connect(){}
    public function testDir($dir = null){}
    public function isDir($dir = null){}
    public function isFile($file = null){}
    public function fileTime($file = null, $m = "m"){}
    public function fileSize($file = null){}
    public function copy($source = null, $target = null, $mode = null){}
    public function mkDir($dir = null){}
    public function lsDir($dir = null){}
    public function rmDir($dir = null){}
    public function rmFile($file = null){}
    public function fileType($file = null){}
    public function setCwd($cwd = null){}
    public function chOwn($file = null, $user = null){}
    public function chGrp($file = null, $group = null){}
    public function isOwn($user = null){}
    public function isGrp($group = null){}
}

/**
* @author setcookie <set@cooki.me>
* @link set.cooki.me
* @copyright Copyright &copy; 2011-2012 setcookie
* @license http://www.gnu.org/copyleft/gpl.html
* @package syncd
* @since 0.0.1
* @desc concrete implementation of sftp protocol
*/
class Sftp extends Ft
{
    protected $_sftp = null;

    /**
     * @throws Exception
     * @return void
     */
    public function connect()
    {
        if(!$this->_conn = ssh2_connect($this->_host, $this->_port))
        {
            throw new Exception("unable to obtain ssh connection");
        }
        if(!ssh2_auth_password($this->_conn, $this->_user, $this->_pass))
        {
            throw new Exception("unable to authenticate to ssh connection");
        }
        if(!$this->_sftp = ssh2_sftp($this->_conn))
        {
            throw new Exception('unable to obtain sftp connection');
        }
    }


    /**
     * @throws Exception
     * @param null $cwd
     * @return null
     */
    public function setCwd($cwd = null)
    {
        if($cwd !== null)
        {
            $this->_cwd = trim((string)$cwd);
        }else{
            if($this->_cwd === "")
            {
                if(($stream = ssh2_exec($this->_conn, "pwd")) !== false)
                {
                    @stream_set_blocking($stream, true);
                    $this->_cwd = rtrim(trim(stream_get_contents($stream)), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                    @fclose($stream);
                }else{
                    throw new Exception("unable to obtain current working directory from stream");
                }
            }
        }
        return $this->_cwd;
    }


    /**
     * @param null $dir
     * @return bool
     */
    public function testDir($dir = null)
    {
        $return = false;
        if($this->isDir($dir))
        {
            $file = "ssh2.sftp://".$this->_sftp . rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$dir, DIRECTORY_SEPARATOR) . ".tmp";
            if((bool)@file_put_contents($file, " "))
            {
                @unlink($file);
                $return = true;
            }
        }
        @clearstatcache();
        return $return;
    }


    /**
     * @param null $dir
     * @return bool
     */
    public function isDir($dir = null)
    {
        $return = false;
        if($dir !== null)
        {
            $dir = "ssh2.sftp://".$this->_sftp . rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$dir, DIRECTORY_SEPARATOR);
            if(is_dir($dir))
            {
                $return = true;
            }
        }
        @clearstatcache();
        return $return;
    }

    /**
     * @param null $file
     * @return bool
     */
    public function isFile($file = null)
    {
        $return = false;
        if($file !== null)
        {
            $file = "ssh2.sftp://".$this->_sftp . rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$file, DIRECTORY_SEPARATOR);
            if(is_file($file))
            {
                $return = true;
            }
        }
        @clearstatcache();
        return $return;
    }

    /**
     * @param null $file
     * @param string $m
     * @return int
     */
    public function fileTime($file = null, $m = "m")
    {
        if($file !== null)
        {
            $t = 0;
            $file = "ssh2.sftp://".$this->_sftp . rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$file, DIRECTORY_SEPARATOR);
            $m = strtolower(trim((string)$m));
            switch($m)
            {
                case "a":
                    if(($t = fileatime($file)) !== false)
                    {
                        return (int)$t;
                    }else{
                        $this->error("unable to obtain fileatime from: $file");
                    }
                    break;
                default:
                    if(($t = filemtime($file)) !== false)
                    {
                        return (int)$t;
                    }else{
                        $this->error("unable to obtain filemtime from: $file");
                    }
            }
        }
        return 0;
    }

    /**
     * @param null $file
     * @return int
     */
    public function fileSize($file = null)
    {
        if($file !== null)
        {
            $s = 0;
            $file = "ssh2.sftp://".$this->_sftp . rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$file, DIRECTORY_SEPARATOR);
            if(($s = filesize($file)) !== false)
            {
                return (int)$s;
            }else{
                $this->error("unable to obtain filesize from: $file");
            }
        }
        return 0;
    }


    /**
     * @param null $source
     * @param null $target
     * @param null $mode
     * @return bool
     */
    public function copy($source = null, $target = null, $mode = null)
    {
        $return = false;

        if($source !== null && $target !== null)
        {
            if($this->_cwd === "/"){
                $target = $this->_cwd . ltrim((string)$target, DIRECTORY_SEPARATOR);
            }else{
                $target = rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$target, DIRECTORY_SEPARATOR);
            }
            if($mode !== null)
            {
                $return = @ssh2_scp_send($this->_conn, $source, $target, $mode);
            }else{
                $return = @ssh2_scp_send($this->_conn, $source, $target);
            }
            if(!$return)
            {
                $this->error("unable to scp copy file: $source to: $target");
            }
        }
        @clearstatcache();
        return $return;
    }

    /**
     * @param null $file
     * @param null $user
     * @return bool
     */
    public function chOwn($file = null, $user = null)
    {
        if($file !== null && $user !== null)
        {
            if($this->_cwd === "/"){
                $file = $this->_cwd . ltrim((string)$file, DIRECTORY_SEPARATOR);
            }else{
                $file = rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$file, DIRECTORY_SEPARATOR);
            }
            $return = $this->exec("sudo chown ".trim((string)$user)." $file");
            if(empty($return))
            {
                return true;
            }else{
                $this->error("unable to chown file: $file to: $user");
            }
        }
        return false;
    }

    /**
     * @param null $file
     * @param null $group
     */
    public function chGrp($file = null, $group = null)
    {
        if($file !== null && $group !== null)
        {
            if($this->_cwd === "/"){
                $file = $this->_cwd . ltrim((string)$file, DIRECTORY_SEPARATOR);
            }else{
                $file = rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$file, DIRECTORY_SEPARATOR);
            }
            $return = $this->exec("sudo chgrp ".trim((string)$group)." $file");
            if(empty($return))
            {
                return true;
            }else{
                $this->error("unable to chgrp file: $file to: $group");
            }
        }
        return false;
    }

    /**
     * @param null $user
     * @return bool
     */
    public function isOwn($user = null)
    {
        if($user !== null)
        {
            $return = $this->exec("sudo grep ".trim((string)$user)." /etc/passwd");
            if(!empty($return))
            {
                return true;
            }
        }
        return false;
    }

    /**
     * @param null $group
     * @return bool
     */
    public function isGrp($group = null)
    {
        if($group !== null)
        {
            $return = $this->exec("sudo grep ".trim((string)$group)." /etc/group");
            if(!empty($return))
            {
                return true;
            }
        }
        return false;
    }

    /**
     * @param null $dir
     * @param int $mode
     * @return bool
     */
    public function mkDir($dir = null, $mode = 0775)
    {
        $return = false;
        if($dir !== null)
        {
            $dir = "ssh2.sftp://".$this->_sftp . rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$dir, DIRECTORY_SEPARATOR);
            if(@mkdir($dir, $mode))
            {
                $return = true;
            }else{
                $this->error("unable to create dir: $dir with permissions: $mode");
            }
        }
        @clearstatcache();
        return $return;
    }

    /**
     * @param null $dir
     * @return void
     */
    public function lsDir($dir = null)
    {
        $ls = false;

        if($dir !== null)
        {
            function _lsDir($b = null, $d = null, Array &$tmp = array())
            {
                $b = rtrim($b, DIRECTORY_SEPARATOR);
                $d = DIRECTORY_SEPARATOR . trim($d, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

                if(is_dir($b . $d) && ($h = @opendir($b . $d)) !== false)
                {
                    while(($f = readdir($h)) !== false)
                    {
                        $f = ltrim($f, DIRECTORY_SEPARATOR);
                        if(substr($f, 0, 1) !== "." && substr($f, 0, 2) !== "..")
                        {
                            $tmp[] = $d . $f;
                            if(is_dir($b . $d . $f))
                            {
                                _lsDir($b, $d . $f, $tmp);
                            }
                        }
                    }
                    @closedir($h);
                }else{
                    return false;
                }
                @clearstatcache();
                return array_reverse($tmp);
            }

            $base = "ssh2.sftp://".$this->_sftp . rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $dir = ltrim((string)$dir, DIRECTORY_SEPARATOR);

            if(($ls = _lsDir($base, $dir)) !== false)
            {
                return $ls;
            }else{
                $this->error("unable to list dir: $base . $dir");
            }
        }
        return false;
    }

    /**
     * @param null $dir
     * @return void
     */
    public function rmDir($dir = null)
    {
        if($dir !== null)
        {
            $dir = "ssh2.sftp://".$this->_sftp . rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$dir, DIRECTORY_SEPARATOR);

            function _rmDir($d = null, &$e)
            {
                if($d !== null)
                {
                    $d = rtrim($d, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                    if(is_dir($d) && ($files = @scandir($d)) !== false)
                    {
                        foreach($files as $f)
                        {
                            if(substr($f, 0, 1) !== "." && substr($f, 0, 2) !== "..")
                            {
                                if(is_dir($d . $f))
                                {
                                    _rmDir($d . $f, $e);
                                }else{
                                    if(!@unlink($d . $f))
                                    {
                                       Ft::error("unable to delete file: $d . $f", "error"); $e = true;
                                    }
                                }
                            }
                        }
                        @reset($files);
                        if(!@rmdir($d))
                        {
                            Ft::error("unable to delete dir: $d", "error"); $e = true;
                        }
                    }
                }
            }

            _rmDir($dir, $e);

            if(!$e)
            {
                return true;
            }else{
                $this->error("unable to delete dir: $dir");
            }
        }
    }

    /**
     * @param null $file
     * @return void
     */
    public function rmFile($file = null)
    {
        if($file !== null)
        {
            $_file = "ssh2.sftp://".$this->_sftp . rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$file, DIRECTORY_SEPARATOR);
            if(!@unlink($_file))
            {
                if($this->exec("chmod 0777 ". DIRECTORY_SEPARATOR . ltrim((string)$file, DIRECTORY_SEPARATOR)) && @unlink($_file))
                {
                    return true;
                }
                $this->error("unable to delete file: $_file");
            }else{
                return true;
            }
        }
        return false;
    }

    /**
     * @param null $file
     * @return bool|string
     */
    public function fileType($file = null)
    {
        if($file !== null)
        {
            $file = "ssh2.sftp://".$this->_sftp . rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$file, DIRECTORY_SEPARATOR);
            if(($type = @filetype($file)) !== false)
            {
                return strtolower($type);
            }else{
                $this->error("unable to obtain file type from: $file");
            }
        }
        return false;
    }

    /**
     * @param null $cmd
     * @return string
     */
    public function exec($cmd = null)
    {
        $stream = null;
        $err_stream = null;

        if($cmd !== null)
        {
            if(($stream = ssh2_exec($this->_conn, escapeshellcmd((string)$cmd), false)) !== false)
            {
                stream_set_blocking($stream, true);
                $err_stream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
                stream_set_blocking($err_stream, true);
                $out = trim(stream_get_contents($stream));
                $err = trim(stream_get_contents($err_stream));
                @fclose($stream);
                @fclose($err_stream);
                if(!empty($err))
                {
                    $this->error("unable to exec command: $cmd in terminal - $err");
                    return $err;
                }else{
                    return $out;
                }
            }else{
                $this->error("unable to exec command: $cmd in terminal");
            }
        }
        return false;
    }
}

/**
* @author setcookie <set@cooki.me>
* @link set.cooki.me
* @copyright Copyright &copy; 2011-2012 setcookie
* @license http://www.gnu.org/copyleft/gpl.html
* @package syncd
* @since 0.0.3
* @desc concrete implementation of local to local filesystem operations
*/
class Fs extends FT
{
    /**
     *
     */
    public function connect(){}

    /**
     * @throws Exception
     * @param null $cwd
     * @return null
     */
    public function setCwd($cwd = null)
    {
        if($cwd !== null)
        {
            $this->_cwd = trim((string)$cwd);
        }
        return $this->_cwd;
    }

    /**
     * @param null $dir
     * @return bool
     */
    public function testDir($dir = null)
    {
        $return = false;
        if($this->isDir($dir))
        {
            $file = rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . rtrim((string)$dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ".tmp";
            if((bool)@file_put_contents($file, " "))
            {
                @unlink($file);
                $return = true;
            }
        }
        @clearstatcache();
        return $return;
    }

    /**
     * @param null $dir
     * @return bool
     */
    public function isDir($dir = null)
    {
        $return = false;
        $dir = rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$dir, DIRECTORY_SEPARATOR);
        if($dir !== null)
        {
            if(is_dir($dir))
            {
                $return = true;
            }
        }
        @clearstatcache();
        return $return;
    }

    /**
     * @param null $file
     * @return bool
     */
    public function isFile($file = null)
    {
        $return = false;
        $file = rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$file, DIRECTORY_SEPARATOR);
        if($file !== null)
        {
            if(is_file($file))
            {
                $return = true;
            }
        }
        @clearstatcache();
        return $return;
    }

    /**
     * @param null $file
     * @param string $m
     * @return int
     */
    public function fileTime($file = null, $m = "m")
    {
        if($file !== null)
        {
            $t = 0;
            $m = strtolower(trim((string)$m));
            $file = rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$file, DIRECTORY_SEPARATOR);
            switch($m)
            {
                case "a":
                    if(($t = fileatime($file)) !== false)
                    {
                        return (int)$t;
                    }else{
                        $this->error("unable to obtain fileatime from: $file");
                    }
                    break;
                default:
                    if(($t = filemtime($file)) !== false)
                    {
                        return (int)$t;
                    }else{
                        $this->error("unable to obtain filemtime from: $file");
                    }
            }
        }
        return 0;
    }

    /**
     * @param null $file
     * @return int
     */
    public function fileSize($file = null)
    {
        if($file !== null)
        {
            $s = 0;
            $file = rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$file, DIRECTORY_SEPARATOR);
            if(($s = filesize($file)) !== false)
            {
                return (int)$s;
            }else{
                $this->error("unable to obtain filesize from: $file");
            }
        }
        return 0;
    }

    /**
     * @param null $source
     * @param null $target
     * @param null $mode
     * @return bool
     */
    public function copy($source = null, $target = null, $mode = null)
    {
        $mask = 0;
        $return = false;

        if($source !== null && $target !== null)
        {
            if($this->_cwd === "/"){
                $target = $this->_cwd . DIRECTORY_SEPARATOR . ltrim((string)$target, DIRECTORY_SEPARATOR);
            }else{
                $target = rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$target, DIRECTORY_SEPARATOR);
            }
            $return = @copy($source, $target);
            if($return)
            {
                if($mode !== null)
                {
                    $mask = @umask(0);
                    if(@chmod($target, $mode))
                    {
                        @umask($mask);
                        $return = true;
                    }else{
                        $this->error("unable to chmod file: $target to: $mode");
                    }
                }else{
                    $return = true;
                }
            }else{
                $this->error("unable to copy file: $source to: $target");
            }
        }
        @clearstatcache();
        return $return;
    }

    /**
     * @param null $file
     * @param null $user
     * @return bool
     */
    public function chOwn($file = null, $user = null)
    {
        $return = false;

        if($file !== null && $user !== null)
        {
            if($this->_cwd === "/"){
                $file = $this->_cwd . DIRECTORY_SEPARATOR . ltrim((string)$file, DIRECTORY_SEPARATOR);
            }else{
                $file = rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$file, DIRECTORY_SEPARATOR);
            }
            $return = @chown($file, (string)$user);
            if(!$return)
            {
                $this->error("unable to chown file: $file to: $user");
            }
        }
        @clearstatcache();
        return $return;
    }

    /**
     * @param null $file
     * @param null $group
     * @return bool
     */
    public function chGrp($file = null, $group = null)
    {
        $return = false;

        if($file !== null && $group !== null)
        {
            if($this->_cwd === "/"){
                $file = $this->_cwd . DIRECTORY_SEPARATOR . ltrim((string)$file, DIRECTORY_SEPARATOR);
            }else{
                $file = rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$file, DIRECTORY_SEPARATOR);
            }
            $return = @chgrp($file, (string)$group);
            if(!$return)
            {
                $this->error("unable to chgrp file: $file to: $group");
            }
        }
        @clearstatcache();
        return $return;
    }

    /**
     * @param null $user
     * @return bool
     */
    public function isOwn($user = null)
    {
        if($user !== null)
        {
            return (bool)posix_getpwnam((string)$user);
        }
        return false;
    }

    /**
     * @param null $group
     * @return bool
     */
    public function isGrp($group = null)
    {
        if($group !== null)
        {
            return (bool)posix_getgrnam((string)$group);
        }
        return false;
    }

    /**
     * @param null $dir
     * @param int $mode
     * @return bool
     */
    public function mkDir($dir = null, $mode = 0755)
    {
        $mask = null;
        $return = false;
        if($dir !== null)
        {
            $dir = rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$dir, DIRECTORY_SEPARATOR);
            $mask = @umask(0);
            if(@mkdir($dir, $mode))
            {
                @umask($mask);
                $return = true;
            }else{
                $this->error("unable to create dir: $dir with permissions: $mode");
            }
        }
        @clearstatcache();
        return $return;
    }

    /**
     * @param null $dir
     * @return void
     */
    public function lsDir($dir = null)
    {
        $ls = false;

        if($dir !== null)
        {
            function _lsDir($b = null, $d = null, Array &$tmp = array())
            {
                $b = rtrim($b, DIRECTORY_SEPARATOR);
                $d = DIRECTORY_SEPARATOR . trim($d, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

                if(is_dir($b . $d) && ($h = @opendir($b . $d)) !== false)
                {
                    while(($f = readdir($h)) !== false)
                    {
                        $f = ltrim($f, DIRECTORY_SEPARATOR);
                        if(substr($f, 0, 1) !== "." && substr($f, 0, 2) !== "..")
                        {
                            $tmp[] = $d . $f;
                            if(is_dir($b . $d . $f))
                            {
                                _lsDir($b, $d . $f, $tmp);
                            }
                        }
                    }
                    @closedir($h);
                }else{
                    return false;
                }
                @clearstatcache();
                return array_reverse($tmp);
            }

            $base = rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $dir = ltrim((string)$dir, DIRECTORY_SEPARATOR);

            if(($ls = _lsDir($base, $dir)) !== false)
            {
                return $ls;
            }else{
                $this->error("unable to list dir: $base . $dir");
            }
        }
        return false;
    }

    /**
     * @param null $dir
     * @return void
     */
    public function rmDir($dir = null)
    {
        $e = false;

        if($dir !== null)
        {
            $dir = rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$dir, DIRECTORY_SEPARATOR);

            function _rmDir($d = null, &$e)
            {
                if($d !== null)
                {
                    $d = rtrim($d, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                    if(is_dir($d) && ($files = @scandir($d)) !== false)
                    {
                        foreach($files as $f)
                        {
                            if(substr($f, 0, 1) !== "." && substr($f, 0, 2) !== "..")
                            {
                                if(is_dir($d . $f))
                                {
                                    _rmDir($d . $f, $e);
                                }else{
                                    if(!@unlink($d . $f))
                                    {
                                       Ft::error("unable to delete file: $d . $f", "error"); $e = true;
                                    }
                                }
                            }
                        }
                        @reset($files);
                        if(!@rmdir($d))
                        {
                            Ft::error("unable to delete dir: $d", "error"); $e = true;
                        }
                    }
                }
            }

            _rmDir($dir, $e);

            if(!$e)
            {
                return true;
            }else{
                $this->error("unable to delete dir: $dir");
            }
        }
        return false;
    }

    /**
     * @param null $file
     * @return void
     */
    public function rmFile($file = null)
    {
        if($file !== null)
        {
            $_file = rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$file, DIRECTORY_SEPARATOR);
            if(!@unlink($_file))
            {
                if(chmod(DIRECTORY_SEPARATOR . ltrim($file, DIRECTORY_SEPARATOR), 0777) && @unlink($_file))
                {
                    return true;
                }
                $this->error("unable to delete file: $_file");
            }else{
                return true;
            }
        }
        return false;
    }

    /**
     * @param null $file
     * @return bool|string
     */
    public function fileType($file = null)
    {
        if($file !== null)
        {
            $file = rtrim((string)$this->_cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string)$file, DIRECTORY_SEPARATOR);
            if(($type = @filetype($file)) !== false)
            {
                return strtolower($type);
            }else{
                $this->error("unable to obtain file type from: $file");
            }
        }
        return false;
    }
}

/*************************************************************************************
 *
 * run script via cli
 * 
*************************************************************************************/
if((int)$argc >= 2)
{
    try
    {
        Syncd::run($argv[1], (isset($argv[2])) ? $argv[2] : null);
    }
    catch(Exception $e)
    {
        echo "\033[01;31m".$e->getMessage()."\033[0m\n";
    }
}

?>