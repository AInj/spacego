<?php
    $backupSkip = array('.git', '_work', 'file/backup');

    class backup
    {
        public $dir = 'file/backup';
        protected $infoFile = '_backupInfo.txt';
        protected $infoKeys = array('type', 'flag', 'time', 'creator', 'creator_notes');

        public function scan($filter, $dir = null)
        {
            if(is_null($dir)) $dir = ROOT_DIR . '/' . $this->dir;
            $found = array();
            foreach(glob($dir . '/*.zip') as $scan)
                if($this->parse($scan)['type'] == $filter)
                    array_push($found, $scan);
            return $found;
        }

        public function parse($file)
        {
            if(!file_exists($file)) return;
            $result = array();
            $parse = explode('_', basename($file, '.zip'));
            $result['time'] = $parse[0];
            $result['type'] = $parse[1];
            $result['flag'] = $parse[2];
            return $result;
        }

        public function read($file)
        {
            $zip = new ZipArchive;
            if($zip->open($file) === true)
            {
                $result = parse_ini_string($zip->getFromName($this->infoFile));
                $zip->close();
            }
            return $result;
        }

        public function retName($type)
        {
            return ($type == 'file' ? 'File' : 'Database') . ' Backup';
        }

        public function create($type, $flag = 'x', $creator = 'system', $notes = 'N/A', $skip = array())
        {
            try
            {
                global $backupSkip, $config;
                $backupSkip = array_merge($backupSkip, $skip);
                $backup = date('d-m-Y-H-i_') . $type . '_' . $flag;
                $file = ROOT_DIR . '/' . $this->dir . '/' . $backup . '.zip';
                $zip = new ZipArchive;
                if($type == 'file') ExtendedZip::zipTree(ROOT_DIR, $file, ZipArchive::CREATE);
                $zip = new ZipArchive;
                if($zip->open($file, ZipArchive::CREATE) === true)
                {
                    if($type == 'db')
                    {
                        $dbfile = ROOT_DIR . '/' . $this->dir . '/' . $backup . '.sql';
                        $dump = new Ifsnop\Mysqldump\Mysqldump("mysql:host={$config['db_host']};dbname={$config['db_database']}", $config['db_username'], $config['db_password']);
                        $dump->start($dbfile);
                        $zip->addFromString(basename($dbfile), file_get_contents($dbfile));
                        unlink($dbfile);
                    }
                    $zip->addFromString($this->infoFile, $this->infoGenerate($type, $flag, date('d/m/Y, H:i:s'), $creator, $notes));
                    $zip->close();
                }
                return $backup . '.zip';
            }
            catch (Exception $e)
            {
                return 0;
            }
        }

        public function restore($file)
        {
            if(!file_exists($file)) return;
            $zip = new ZipArchive;
            if($zip->open($file) === true)
            {
                global $db, $config;
                $db->exec('SET FOREIGN_KEY_CHECKS = 0');
                $query = "
                    SELECT concat('DROP TABLE IF EXISTS ', table_name, ';')
                    FROM information_schema.tables
                    WHERE table_schema = '{$config['db_database']}'";
                foreach($db->query($query) as $row)
                    $db->exec($row[0]);
                $db->exec('SET FOREIGN_KEY_CHECKS = 1');
                $db->exec($zip->getFromName(basename($file, '.zip').'.sql'));
                $zip->close();
                return true;
            }
            return false;
        }

        public function delete($file)
        {
            return unlink(ROOT_DIR . '/' . $this->dir . '/' . $file);
        }

        protected function infoGenerate()
        {
            if(func_num_args() != count($this->infoKeys)) return;
            global $env, $build;
            $args = func_get_args();
            $result = '';
            foreach($this->infoKeys as $key)
                $result .= $key . '=' . $args[array_search($key, $this->infoKeys)] . $env['nl'];
            $result .= '[generated by backup library on version ' . $build['version'] . ' (build ' . $build['build'] . ')]' . $env['nl'];
            return $result;
        }
    }

    class ExtendedZip extends ZipArchive { // By Giorgio Barchiesi, Stack Overflow

        // Member function to add a whole file system subtree to the archive
        public function addTree($dirname, $localname = '') {
            if ($localname)
                $this->addEmptyDir($localname);
            $this->_addTree($dirname, $localname);
        }

        // Internal function, to recurse
        protected function _addTree($dirname, $localname) {
            $dir = opendir($dirname);
            while ($filename = readdir($dir)) {
                // Discard . and ..
                if ($filename == '.' || $filename == '..')
                    continue;

                // Proceed according to type
                $path = $dirname . '/' . $filename;
                $localpath = $localname ? ($localname . '/' . $filename) : $filename;

                // Skip specific paths - Manually added by system developer
                global $backupSkip;
                if(in_array($localpath, $backupSkip)) continue;

                if (is_dir($path)) {
                    // Directory: add & recurse
                    $this->addEmptyDir($localpath);
                    $this->_addTree($path, $localpath);
                }
                else if (is_file($path)) {
                    // File: just add
                    $this->addFile($path, $localpath);
                }
            }
            closedir($dir);
        }

        // Helper function
        public static function zipTree($dirname, $zipFilename, $flags = 0, $localname = '') {
            $zip = new self();
            $zip->open($zipFilename, $flags);
            $zip->addTree($dirname, $localname);
            $zip->close();
        }
    }
?>
