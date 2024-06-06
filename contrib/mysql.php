<?php
/*
## Installing

Add to your _deploy.php_

```php
require 'contrib/mysql.php';
```

## Configuration
- `mysql_dump_switches', mysqldump command line switches
- `mysql_find_replace_table_exclusions', Array of tables to skip when doing a find replace

## Host Configuration
- `mysql_domain`, The domain name of the website, used for find replace
- `mysql_host`, The mysql host
- `mysql_name`, The mysql database name
- `mysql_pass`, The mysql password
- `mysql_port`, The mysql port
- `mysql_user`, The mysql user
- `mysql_ssh`, Set to true if the host requires an ssh tunnel to connect to the mysql server
- `production`, Set production flag as true on production host to prevent any potentially destructive actions from running on this host

## Usage

Provides common mysql sync/backup tasks

```php

```

 */

namespace Deployer;

use Deployer\Host\Host;

//set('mysql_dump_switches', '--max_allowed_packet=128M --single-transaction --quick --extended-insert --allow-keywords --events --routines --compress --extended-insert --create-options --add-drop-table --add-locks --no-tablespaces');
set('mysql_dump_switches', '--max_allowed_packet=512MB --net-buffer-length=2MB --single-transaction --extended-insert --allow-keywords --events --routines --compress --extended-insert --create-options --add-drop-table --add-locks --no-tablespaces');
set('mysql_find_replace_table_exclusions', []);

class Mysql
{
    public function __construct()
    {
        ini_set('max_execution_time', 0);
        $this->validateHosts();
    }

    protected function validateHosts(): bool
    {
        $stages = [
            'development',
            'staging',
            'production'
        ];
        $hosts = Deployer::get()->hosts;
        $hasProduction = false;
        foreach ($hosts as $host) {
            $alias = $host->getAlias();
            if (!$host->hasOwn('stage')) {
                throw error('Stage option must be set for host ' . $alias);
            }
            $stage = $host->get('stage');
            if (!in_array($stage, $stages)) {
                throw error('Stage option must be one of [' . implode(', ', $stages) . '] for host ' . $alias);
            }
            $this->hostCredentials($host);
            if ($stage == 'production') {
                $hasProduction = true;
            }
        }
        if (!$hasProduction) {
            throw error('At least one host stage option must be set to production');
        }
        return true;
    }

    public function hostCredentials(Host $host): object
    {
        $required = [
            'mysql_domain',
            'mysql_host',
            'mysql_name',
            'mysql_pass',
            'mysql_port',
            'mysql_user'
        ];
        $credentials = [];

        $credentials = new \stdClass();
        foreach ($required as $key) {
            if ($host->hasOwn($key) === false) {
                throw error("$key is not defined");
            }
            $value = $host->get($key);

            $shortKey = str_replace('mysql_', '', $key);
            $value = str_replace('`', '\`', $value);
            $value = str_replace('"', '\"', $value);
            $credentials->{$shortKey} = $value;
        }
        return $credentials;
    }

    protected function hostPortUserPassword(object $H): string
    {
        $hostPortUserPassword = sprintf('--host="%s" --port="%s" --user="%s" --password="%s"', $H->host, $H->port, $H->user, $H->pass);
        return $hostPortUserPassword;
    }

    /* ----------------- Command Creation ----------------- */

    protected function mysqlImportCommand(Host $destination): string
    {
        $mysql = sshWhich('mysql', $destination);
        $D = $this->hostCredentials($destination);
        $destHostPortUserPassword = $this->hostPortUserPassword($D);

        $importCommand = sprintf('%s %s %s', $mysql, $destHostPortUserPassword, $D->name);
        $importCommandPrefixed = sshPrefix($destination, hostLocalhost(), $importCommand);
        return $importCommandPrefixed;
    }

    protected function mysqlDumpCommand(Host $source): string
    {
        $mysqldump = sshWhich('mysqldump', $source);
        $dumpSwitches = get('mysql_dump_switches');
        $H = $this->hostCredentials($source);
        $hostPortUserPassword = $this->hostPortUserPassword($H);

        $dumpCommand = sprintf('%s %s %s %s', $mysqldump, $dumpSwitches, $hostPortUserPassword, $H->name);
        $dumpCommand = sshPrefix($source, hostLocalhost(), $dumpCommand);
        return $dumpCommand;
    }

    public function findReplaceCommand(Host $source, Host $destination): string
    {
        $php = sshWhich('php', $destination);
        $scriptDir = hostIsLocalhost($destination) ? $destination->getDeployPath() : hostCurrentDir($destination);
        $script = $scriptDir . "/vendor/interconnectit/search-replace-db/srdb.cli.php";

        $tableExclusions = implode(',', get('mysql_find_replace_table_exclusions', ''));
        if (!empty($tableExclusions)) {
            $tableExclusions = sprintf('--exclude-tables="%s"', $tableExclusions);
        }

        $S = $this->hostCredentials($source);
        $D = $this->hostCredentials($destination);

        $sprintTemplate = '%s %s %s --host="%s" --page-size=50000 --port="%s" --user="%s" --pass="%s" --name="%s" --search="%s" --replace="%s"';
        $replaceCommand = sprintf($sprintTemplate, $php, $script, $tableExclusions, $D->host, $D->port, $D->user, $D->pass, $D->name, $S->domain, $D->domain);
        $replaceCommand = sshPrefix($destination, hostLocalhost(), $replaceCommand);

        return $replaceCommand;
    }

    public function pullCommand(Host $source, Host $destination): string
    {
        return sprintf('%s | %s', $this->mysqlDumpCommand($source), $this->mysqlImportCommand($destination));
    }

    public function backupCommand(Host $source): string
    {
        $H = $this->hostCredentials($source);
        $dumpCommand = $this->mysqlDumpCommand($source);

        $gzip = whichLocal('gzip');
        $dumpName = sprintf('db-%s-%s-%s.sql', $source->getAlias(), $H->name, date('YmdHis'));
        $backupCommand = sprintf('%s > "%s" && %s "%s"', $dumpCommand, $dumpName, $gzip, $dumpName);
        return $backupCommand;
    }

    /* ----------------- Mysql Methods ----------------- */

    /**
     * Backup a remote database to the local filesystem
     *
     * @return void
     */
    public function backup(Host $source): void
    {
        $backupCommand = $this->backupCommand($source);
        runOnHost(hostLocalhost(), $backupCommand, ['timeout' => 0, 'idle_timeout' => 0]);
    }

    /**
     * Truncate all tables in the host database
     *
     * @param Host $host
     * @return void
     */
    public function clear(Host $host): void
    {
        if (hostIsProduction($host)) {
            throw error("Command cannot be run on production");
        }

        $mysql = sshWhich('mysql', $host);

        $H = $this->hostCredentials($host);
        $connection = sprintf('%s %s %s', $mysql, $this->hostPortUserPassword($H), $H->name);

        $tablesCommand = sprintf('%s -e "SHOW TABLES;"', $connection);
        $tablesCommandPrefixed = sshPrefix($host, hostLocalhost(), $tablesCommand);
        $tables = runOnHost(hostLocalhost(), $tablesCommandPrefixed);

        $tableArray = explode(PHP_EOL, $tables);
        unset($tableArray[0]); // removes 'Tables in dbname' entry
        if (empty($tableArray)) {
            warning("No tables found in {$H->name}");
            return;
        }

        $dropTables = implode(',', $tableArray);
        $dropCommand = sprintf('%s -e "DROP TABLE IF EXISTS %s;"', $connection, $dropTables);
        $dropCommandPrefixed = sshPrefix($host, hostLocalhost(), $dropCommand);
        runOnHost(hostLocalhost(), $dropCommandPrefixed);
        // info("Dropped tables $dropTables");
    }

    /**
     * Pull a remote mysql database to destination host
     *
     * @param Host $host
     * @return void
     */
    public function pull(Host $source, Host $destination): void
    {
        if (hostIsLocalhost($source)) {
            throw error("Source host cannot be localhost when pulling databases");
        }
        if (hostsAreSame($source, $destination)) {
            throw error("Hosts source and destination cannot be the same host when pulling databases");
        }
        if (hostIsProduction($destination)) {
            throw error("Command cannot be run on production");
        }
        $this->clear($destination);
        $pullCommand = $this->pullCommand($source, $destination);
        runOnHost(hostLocalhost(), $pullCommand, ['real_time_output' => false, 'timeout' => 0, 'idle_timeout' => 0]);
    }

    /**
     * Find a replace name of the source domain with the destination domain in a mysql database
     *
     * @param Host $source
     * @param Host $destination
     * @return void
     */
    public function findReplace(Host $source, Host $destination): void
    {
        if (hostsAreSame($source, $destination)) {
            throw error("Hosts source and destination cannot be the same host when find replacing");
        }
        if (hostIsProduction($destination)) {
            throw error("Command cannot be run on production");
        }
        $replaceCommand = $this->findReplaceCommand($source, $destination);
        runOnHost(hostLocalhost(), $replaceCommand, ['real_time_output' => true, 'timeout' => 0, 'idle_timeout' => 0]);
    }
}

// Tasks

task('db:backup', function () {
    $mysql = new Mysql();
    $mysql->backup(currentHost());
})->desc('Pull db from a remote host to localhost using mysqldump');

task('db:clear', function () {
    $mysql = new Mysql();
    $mysql->clear(currentHost());
})->desc('Clear all tables from localhost database');

task('db:pull', function () {
    $mysql = new Mysql();
    $mysql->pull(currentHost(), hostLocalhost());
})->desc('Pull db from a remote host to localhost using mysqldump');

task('db:replace', function () {
    $mysql = new Mysql();
    $mysql->findReplace(currentHost(), hostLocalhost());
})->desc('Replace the host domain with the localhost domain in the local database');

task('db:pull-replace', function () {
    $mysql = new Mysql();
    $mysql->pull(currentHost(), hostLocalhost());
    $mysql->findReplace(currentHost(), hostLocalhost());
})->desc('Pull db from a remote host to localhost using mysqldump and replace the host domain with the localhost domain in the local database');
