<?php

/**
 * Lightweight utility for managing database migrations and seeding.
 *
 * HT: Kyle Ladd's novice class (https://github.com/kladd/slim-eloquent)
 * 
 */
class Lightswitch
{
    /**
     * @var array
     */
    private $args;
    
    /**
     * @var array
     */
    private $cfg;

    /**
     * @var Illuminate\Database\Capsule\Manager
     */
    private $db;

    /**
     * @var array
     */
    private $history;

    /**
     * @var timestamp The default value used to init the history
     */
    private $defaultTimestamp = 342144000;

    



    public function __construct(Illuminate\Database\Capsule\Manager $db, array $config, $args)
    {
        $this->db = $db;
        $this->cfg = array_merge(static::getDefaultConfig(), $config);
        $this->args = $args;
        $this->loadHistory();
    }


    /**
     * Get default configuration values
     * @return array
     */
    public static function getDefaultConfig()
    {
// define("SITEROOT", dirname(__FILE__) . '/');
// define("MIGRATIONS", SITEROOT."sql/migrations/");
// define("SEEDS", SITEROOT."sql/seeds/");
// define("TEMPLATES", SITEROOT."sql/templates/");
// define("HISTORY_FILE", SITEROOT."sql/history.json");

// define("MIGR_PREFIX", "migr_");
// define("SEED_PREFIX", "seed_");
// define("MIGR_SUFFIX", "_");
// define("SEED_SUFFIX", "_");

        return array(
        	// 
        	'sqlroot' => '',
        	'migrations.' => '',
        	'' => '',
        	'' => '',
        	'' => '',
        	'' => '',
        	'' => '',
        	'' => ''
        );
    }


    
    private function loadHistory()
    {
        //todo: someday let's validate the contents if it does exist
        if (!file_exists(HISTORY_FILE)) {
            $this->history = array(
                'appliedMigrations' => array(), 
                'lastMigration' => $this->defaultTimestamp,
                'seedGroups' => array(
                    'default' => array(
                        'lastSeed' => $this->defaultTimestamp,
                        'appliedSeeds' => array()
                        )
                    ) 
                );
            $this->saveHistory();
        }
       $this->history = json_decode(file_get_contents(HISTORY_FILE));
    }

    private function initSeedGroupHistory($group)
    {
        if (isset($this->history->seedGroups->$group)) { return; }
        $this->history->seedGroups->$group = array(
                        'lastSeed' => $this->defaultTimestamp,
                        'appliedSeeds' => array()
                        );
        $this->saveHistory();
        $this->loadHistory();
    }

    private function saveHistory()
    {
        file_put_contents(HISTORY_FILE, json_encode($this->history, JSON_PRETTY_PRINT));
    }


    private function help($oops = false)
    {
        if ($oops) {
            echo "\n";
            echo "Oops! Try something like this...\n";
        }
        $mefile = $this->args[0];
        echo "\n";
        echo "/ - - - - - - - - - - - - - - - - - - - - - - - - -\n";
        echo "| Lightswitch Usage \n";
        echo "| - - - - - - - - - - - - - - - - - - - - - - - - -\n";
        echo "| \n";
        echo "| Run all MIGRATIONS that haven't been run \n";
        echo "|   php $mefile migrate \n";
        echo "| \n";
        echo "| Run all SEEDS that haven't been run in the default seed group \n";
        echo "|   php $mefile seed \n";
        echo "| \n";
        echo "| Run all SEEDS that haven't been run in the given seed group \n";
        echo "|   php $mefile seed seedGroup \n";
        echo "| \n";
        echo "| Display the STATUS of all migrations and seeds \n";
        echo "|   php $mefile status \n";
        echo "| \n";
        echo "| - Design time helpers - - - - -\n";
        echo "| \n";
        echo "| Create a new migration file. \n";
        echo "|   php $mefile new migration [name]\n";
        echo "| \n";
        echo "| Create a new seed file. \n";
        echo "|   php $mefile new seed [name]\n";
        echo "| \n";
        echo "\ - - - - - - - - - - - - - - - - - - - - - - - - -\n";
        echo "\n";
    }


    public function exec()
    {
        // remember, first arg is the name of the currently executing file
        $argCount = count($this->args);

        if ($argCount < 2 || $argCount > 4) {
            $this->help(true);
            return;
        }

        if ($argCount == 2) {
            switch ($this->args[1]) {
                case "migrate":
                    $this->runMigrations();
                    break;
                case "seed":
                    $this->runSeeds();
                    break;
                case "status":
                    $this->runStatus();
                    break;
                default:
                    $this->help();
                    break;
            }
            return;
        }

        // handle running of named seed group
        if ($this->args[1] == 'seed') {
            $this->runSeeds($this->args[2]);
            return;
        }

        if ($this->args[1] != 'new') {
            $this->help(true);
            return;
        }

        if ($this->args[2] != 'migration' && $this->args[2] != 'seed') {
            $this->help(true);
            return;
        }

        $name = isset($this->args[3])
            ? $this->args[3]
            : '';
            
        switch ($this->args[2]) {
            case "migration":
                $this->newMigration($name);
                break;
            case "seed":
                $this->newSeed($name);
                break;
        }
    }


    private function newMigration($name)
    {
        $cleanName = $this->getCleanName($name);
        $classname = MIGR_PREFIX.time().MIGR_SUFFIX.$cleanName;
        $filename = $classname.'.php';
        $filepath = MIGRATIONS.$filename;

        $template = file_get_contents(TEMPLATES.'migration.php');
        $content = str_replace('{{classname}}', $classname, $template);
        file_put_contents($filepath, $content);
        echo "New migration created at: $filepath \n";
    }


    private function newSeed($name)
    {
        $cleanName = $this->getCleanName($name);
        $classname = SEED_PREFIX.time().SEED_SUFFIX.$cleanName;
        $filename = $classname.'.php';
        $filepath = SEEDS.$filename;

        $template = file_get_contents(TEMPLATES.'seed.php');
        $content = str_replace('{{classname}}', $classname, $template);
        file_put_contents($filepath, $content);
        echo "New seed created at: $filepath \n";
    }


    private function runStatus()
    {
        $dbname = $this->db->getConnection()->getDatabaseName();
        $hasMigratedBefore = $this->history->lastMigration != $this->defaultTimestamp;

        echo "\n";
        echo "/ - - - - - - - - - - - - - - - - - - - - - - - - -\n";
        echo "| Lightswitch Status  \n";
        echo "| Database: $dbname \n";
        echo "| Reporting Timezone: ".REPORTING_TIMEZONE." \n";
        echo "| - - - - - - - - - - - - - - - - - - - - - - - - -\n";
        echo "| \n";
        echo "| - Completed Migrations - - - - -\n";
        if ($hasMigratedBefore) {
            foreach ($this->history->appliedMigrations as $migration) {
                $ranOnAsDateTimeString = Carbon::createFromTimeStamp($migration->ranOn, REPORTING_TIMEZONE)->toDateTimeString();
                echo "| $ranOnAsDateTimeString $migration->class \n";
            }
        } else {
            echo "| No migrations have been run against this database. \n";
        }
        echo "| \n";
        echo "| - Open Migrations - - - - -\n";
        $openMigrations = $this->getMigrationsNotYetRun();
        if (count($openMigrations) > 0) {
            foreach ($openMigrations as $migration) {
                $className = $this->getClassNameFromFilePath($migration);
                echo "| $className \n";
            }
        } else {
            echo "| All migrations have been run against this database. \n";
        }
        echo "| \n";
        echo "| - - - - - - - - - - - - - - - - - - - - - - - - -\n";
        echo "| \n";
        foreach ($this->history->seedGroups as $groupName => $groupHistory) {
            $hasSeededBefore = $groupHistory->lastSeed != $this->defaultTimestamp;
            echo "| - $groupName Seeds - - - - -\n";
            if ($hasSeededBefore) {
                foreach ($groupHistory->appliedSeeds as $seed) {
                    $ranOnAsDateTimeString = Carbon::createFromTimeStamp($seed->ranOn, REPORTING_TIMEZONE)->toDateTimeString();
                    echo "| $ranOnAsDateTimeString $seed->class \n";
                }
            } else {
                echo "| No $groupName seeds have been run against this database. \n";
            }
            echo "| \n";
        }
        echo "\ - - - - - - - - - - - - - - - - - - - - - - - - -\n";
        echo "\n";
        

    }


    private function runMigrations()
    {
        $files = $this->getMigrationsNotYetRun();
        foreach ($files as $file) {
            $thisMigrationFailed = true;
            try {
                $className = $this->getClassNameFromFilePath($file);
                $migrationTimestamp = $this->getTimestampFromClassName($className);

                echo "Running migration $className. \n";
//                require_once($file);
//                $obj = new $className;
//                $obj->run($this->db);
                echo "Migration $className completed. \n";

                $thisMigrationFailed = false;
            } catch (\Exception $exc) {
                echo "Migration $className failed. \n";
                echo $exc->getTraceAsString()."\n";
                break;
            } finally {
                if ($thisMigrationFailed) { return; }
                $this->history->lastMigration = $migrationTimestamp;
                $this->history->appliedMigrations[] = array(
                    'ranOn' => time(),
                    'class' => $className
                    );
                $this->saveHistory();
            }
        }
    }


    private function runSeeds($group = 'default')
    {
        $this->initSeedGroupHistory($group);
        $files = $this->getSeedsNotYetRunForGroup($group);
        var_dump($files);
        $seedGroupHistory = $this->history->seedGroups->$group;
        foreach ($files as $file) {
            $thisSeedFailed = true;
            try {
                $className = $this->getClassNameFromFilePath($file);
                $seedTimestamp = $this->getTimestampFromClassName($className);

                echo "Running seed $className. \n";
//                require_once($file);
//                $obj = new $className;
//                $obj->run($this->db);
                echo "Seed $className completed. \n";

                $thisSeedFailed = false;
            } catch (\Exception $exc) {
                echo "Seed $className failed. \n";
                echo $exc->getTraceAsString()."\n";
                break;
            } finally {
                if ($thisSeedFailed) { return; }
                $seedGroupHistory->lastSeed = $seedTimestamp;
                $seedGroupHistory->appliedSeeds[] = array(
                    'ranOn' => time(),
                    'class' => $className
                    );
                $this->saveHistory();
            }
        }
    }


    private function getCleanName($name)
    {
        if (empty($name)) { return ''; }
        return preg_replace('/[^a-zA-Z0-9]/', '', $name);
    }

    private function getMigrationsNotYetRun()
    {
        $files = glob(MIGRATIONS.'*.php');
        $notRun = array_filter($files, function($file) //use($NUM)
            {
                $className = $this->getClassNameFromFilePath($file);
                $migrationTimestamp = $this->getTimestampFromClassName($className);
                return $migrationTimestamp > $this->history->lastMigration;
            });
        return $notRun;
    }

    private function getSeedsNotYetRunForGroup($group)
    {
        $files = glob(SEEDS.$group.'/*.php');
        $seedGroupHistory = $this->history->seedGroups->$group;
        $notRun = array_filter($files, function($file) use($seedGroupHistory)
            {
                $className = $this->getClassNameFromFilePath($file);
                $seedTimestamp = $this->getTimestampFromClassName($className);
                return $seedTimestamp > $seedGroupHistory->lastSeed;
            });
        return $notRun;
    }


    private function getClassNameFromFilePath($filePath)
    {
        return basename($filePath, '.php');
    }

    private function getTimestampFromClassName($className)
    {
        return split('_', $className)[1];
    }

}
