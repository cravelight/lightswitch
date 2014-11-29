<?php

namespace Cravelight;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager;

/**
 * Lightweight utility for managing database migrations and seeding.
 *
 * HT: Kyle Ladd's novice class (https://github.com/kladd/slim-eloquent)
 * 
 */
class Lightswitch
{
    private $argv;
    private $argc;

    /**
     * @var Illuminate\Database\Capsule\Manager
     */
    private $db;

    /**
     * @var timestamp The default value used to init the history
     */
    private $defaultTimestamp = 342144000;

    /**
     * @var array
     */
    private $history;
    private $historyFilePath;

    private $migrationsPath;
    private $migrPrefix;
    private $migrSuffix;

    private $seedsPath;
    private $seedPrefix;
    private $seedSuffix;

    private $templatesPath;




    /**
     * @param Illuminate\Database\Capsule\Manager $db Illuminate database manager instance
     * See Lightswitch::getDefaultConfig for settings which can be overridden.
     */
    public function __construct(Illuminate\Database\Capsule\Manager $db)
    {
        global $argv, $argc;
        $this->argv = $argv;
        $this->argc = $argc;
        $this->db = $db;

        $sqlPath = realpath($this->getVendorParentPath() . '/sql');
        $this->historyFilePath = realpath($sqlPath . '/history.json');
        $this->migrationsPath = realpath($sqlPath . '/migrations');
        $this->migrPrefix = 'migr_';
        $this->migrSuffix = '_';
        $this->seedsPath = realpath($sqlPath . '/seeds');
        $this->seedPrefix = 'seed_';
        $this->seedSuffix = '_';
        $this->templatesPath = realpath($sqlPath . '/templates');

        $this->initPaths();
        $this->initTemplates();
        $this->loadHistory();
    }

    private function getVendorParentPath()
    {
        // yeah, we could use relative .. stuff, but this makes it obvious
        $src = dirname(__FILE__);
        $lightswitch = dirname($src);
        $cravelight = dirname($lightswitch);
        $vendor = dirname($cravelight);
        $parent = dirname($vendor);
        return realpath($parent);
    }

    private function initPaths()
    {
        $paths = array(
            $this->migrationsPath,
            $this->seedsPath,
            $this->templatesPath
        );
        foreach ($paths as $path) {
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        }
    }

    private function initTemplates()
    {
        $templates = array('seed.php', 'migration.php');
        foreach ($templates as $template) {
            $target = realpath($this->templatesPath) . '/' . $template;
            if (!file_exists($target)){
                $source = realpath(dirname(__FILE__)) . '/templates/' . $template;
                copy($source, $target);
            }
        }
    }

    private function loadHistory()
    {
        //todo: someday let's validate the contents if it does exist
        if (!file_exists($this->historyFilePath)) {
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
       $this->history = json_decode(file_get_contents($this->historyFilePath));
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
        file_put_contents($this->historyFilePath, json_encode($this->history, JSON_PRETTY_PRINT));
    }


    private function help($oops = false)
    {
        if ($oops) {
            echo "" . PHP_EOL;
            echo "Oops! Try something like this..." . PHP_EOL;
        }
        $mefile = $this->argv[0];
        echo PHP_EOL;
        echo "/ - - - - - - - - - - - - - - - - - - - - - - - - -" . PHP_EOL;
        echo "| Lightswitch Usage " . PHP_EOL;
        echo "| - - - - - - - - - - - - - - - - - - - - - - - - -" . PHP_EOL;
        echo "| " . PHP_EOL;
        echo "| Run all MIGRATIONS that haven't been run " . PHP_EOL;
        echo "|   php $mefile migrate " . PHP_EOL;
        echo "| " . PHP_EOL;
        echo "| Run all SEEDS that haven't been run in the default seed group " . PHP_EOL;
        echo "|   php $mefile seed " . PHP_EOL;
        echo "| " . PHP_EOL;
        echo "| Run all SEEDS that haven't been run in the given seed group " . PHP_EOL;
        echo "|   php $mefile seed seedGroup " . PHP_EOL;
        echo "| " . PHP_EOL;
        echo "| Display the STATUS of all migrations and seeds " . PHP_EOL;
        echo "|   php $mefile status " . PHP_EOL;
        echo "| " . PHP_EOL;
        echo "| - Design time helpers - - - - -" . PHP_EOL;
        echo "| " . PHP_EOL;
        echo "| Create a new migration file. " . PHP_EOL;
        echo "|   php $mefile new migration [name]" . PHP_EOL;
        echo "| " . PHP_EOL;
        echo "| Create a new seed file. " . PHP_EOL;
        echo "|   php $mefile new seed [name]" . PHP_EOL;
        echo "| " . PHP_EOL;
        echo "\ - - - - - - - - - - - - - - - - - - - - - - - - -" . PHP_EOL;
        echo "" . PHP_EOL;
    }


    public function exec()
    {
        // remember, first arg is the name of the currently executing file

        if ($this->argc < 2 || $this->argc > 4) {
            $this->help(true);
            return;
        }

        if ($this->argc == 2) {
            switch ($this->argv[1]) {
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
        if ($this->argv[1] == 'seed') {
            $this->runSeeds($this->argv[2]);
            return;
        }

        if ($this->argv[1] != 'new') {
            $this->help(true);
            return;
        }

        if ($this->argv[2] != 'migration' && $this->argv[2] != 'seed') {
            $this->help(true);
            return;
        }

        $name = isset($this->argv[3])
            ? $this->argv[3]
            : '';
            
        switch ($this->argv[2]) {
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
        $classname = $this->migrPrefix . time() . $this->migrSuffix . $cleanName;
        $filename = $classname.'.php';
        $filepath = $this->migrationsPath . '/' . $filename;

        $template = file_get_contents($this->templatesPath . '/migration.php');
        $content = str_replace('{{classname}}', $classname, $template);
        file_put_contents($filepath, $content);
        echo "New migration created at: $filepath " . PHP_EOL;
    }


    private function newSeed($name)
    {
        $cleanName = $this->getCleanName($name);
        $classname = $this->seedPrefix . time() .$this->seedSuffix . $cleanName;
        $filename = $classname.'.php';
        $filepath = $this->seedsPath . '/' . $filename;

        $template = file_get_contents($this->templatesPath . '/seed.php');
        $content = str_replace('{{classname}}', $classname, $template);
        file_put_contents($filepath, $content);
        echo "New seed created at: $filepath " . PHP_EOL;
    }


    private function runStatus()
    {
        $dbname = $this->db->getConnection()->getDatabaseName();
        $hasMigratedBefore = $this->history->lastMigration != $this->defaultTimestamp;

        echo "" . PHP_EOL;
        echo "/ - - - - - - - - - - - - - - - - - - - - - - - - -" . PHP_EOL;
        echo "| Lightswitch Status  " . PHP_EOL;
        echo "| Database: $dbname " . PHP_EOL;
        echo "| - - - - - - - - - - - - - - - - - - - - - - - - -" . PHP_EOL;
        echo "| " . PHP_EOL;
        echo "| - Completed Migrations - - - - -" . PHP_EOL;
        if ($hasMigratedBefore) {
            foreach ($this->history->appliedMigrations as $migration) {
                $ranOnAsDateTimeString = Carbon::createFromTimeStamp($migration->ranOn)->toDateTimeString();
                echo "| $ranOnAsDateTimeString $migration->class " . PHP_EOL;
            }
        } else {
            echo "| No migrations have been run against this database. " . PHP_EOL;
        }
        echo "| " . PHP_EOL;
        echo "| - Open Migrations - - - - -" . PHP_EOL;
        $openMigrations = $this->getMigrationsNotYetRun();
        if (count($openMigrations) > 0) {
            foreach ($openMigrations as $migration) {
                $className = $this->getClassNameFromFilePath($migration);
                echo "| $className " . PHP_EOL;
            }
        } else {
            echo "| All migrations have been run against this database. " . PHP_EOL;
        }
        echo "| " . PHP_EOL;
        echo "| - - - - - - - - - - - - - - - - - - - - - - - - -" . PHP_EOL;
        echo "| " . PHP_EOL;
        foreach ($this->history->seedGroups as $groupName => $groupHistory) {
            $hasSeededBefore = $groupHistory->lastSeed != $this->defaultTimestamp;
            echo "| - $groupName Seeds - - - - -" . PHP_EOL;
            if ($hasSeededBefore) {
                foreach ($groupHistory->appliedSeeds as $seed) {
                    $ranOnAsDateTimeString = Carbon::createFromTimeStamp($seed->ranOn)->toDateTimeString();
                    echo "| $ranOnAsDateTimeString $seed->class " . PHP_EOL;
                }
            } else {
                echo "| No $groupName seeds have been run against this database. " . PHP_EOL;
            }
            echo "| " . PHP_EOL;
        }
        echo "\ - - - - - - - - - - - - - - - - - - - - - - - - -" . PHP_EOL;
        echo "" . PHP_EOL;
        

    }


    private function runMigrations()
    {
        $files = $this->getMigrationsNotYetRun();
        foreach ($files as $file) {
            $thisMigrationFailed = true;
            try {
                $className = $this->getClassNameFromFilePath($file);
                $migrationTimestamp = $this->getTimestampFromClassName($className);

                echo "Running migration $className. " . PHP_EOL;
//                require_once($file);
//                $obj = new $className;
//                $obj->run($this->db);
                echo "Migration $className completed. " . PHP_EOL;

                $thisMigrationFailed = false;
            } catch (\Exception $exc) {
                echo "Migration $className failed. " . PHP_EOL;
                echo $exc->getTraceAsString()."" . PHP_EOL;
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

                echo "Running seed $className. " . PHP_EOL;
//                require_once($file);
//                $obj = new $className;
//                $obj->run($this->db);
                echo "Seed $className completed. " . PHP_EOL;

                $thisSeedFailed = false;
            } catch (\Exception $exc) {
                echo "Seed $className failed. " . PHP_EOL;
                echo $exc->getTraceAsString()."" . PHP_EOL;
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
        $files = glob($this->migrationsPath . '/*.php');
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
        $files = glob($this->seedsPath . '/' . $group . '/*.php');
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
