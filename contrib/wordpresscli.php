<?php
/*
## Installing

Add to your _deploy.php_

```php
require 'contrib/wordpresscli.php';
```

## Configuration
- `wpcli_webroot`, Path of the root website folder relative to the site root. Defaults to ''
- `wpcli_domain`, Domain name of wordpress site for use with wp cli
- `bin/wp`, Path to wordpress cli executable
- `wpcli_dir_permissions`, Permission to set directorys to when hardening. Defaults to u=rwx,g=rx,o=rx
- `wpcli_file_permissions` Permission to set files to when hardening. Defaults to u=rw,g=r,o=r
- `wpcli_uploads_dir_permissions`, Permission to set directorys to when hardening. Defaults to u=rwx,g=rwx,o=rx
- `wpcli_uploads_file_permissions` Permission to set files to when hardening. Defaults to u=rw,g=rw,o=r
## Usage

Wordpress cli tasks.  Currently only wp

```php
after('deploy:publish', 'wp:cache:flush');
```

 */

namespace Deployer;

use Deployer\Host\Host;
use Deployer\Host\Localhost;
use Symfony\Component\Console\Input\InputOption;

option('command', null, InputOption::VALUE_REQUIRED, 'Command to execute via wp cli');

set('wpcli_dir_permissions', 'u=rwx,g=rx,o=rx');
set('wpcli_file_permissions', 'u=rw,g=r,o=r');
set('wpcli_uploads_dir_permissions', 'u=rwx,g=rwx,o=rx');
set('wpcli_uploads_file_permissions', 'u=rw,g=rw,o=r');
set('wpcli_uploads_dirs', ['wp-content/uploads']);

class WordpressCli
{
    private $host;

    public function __construct(Host $host)
    {
        $this->host = $host;
    }

    protected function sitePath(): string
    {
        $host = $this->host;
        $deployPath = ($host instanceof Localhost) ? $host->getDeployPath() : $host->get('current_path');
        $root = $host->get('wpcli_webroot', '');
        $rootPath = (!empty($root)) ? $deployPath . '/' . $root : $deployPath;
        if (empty($rootPath)) {
            return '';
        }
        return $rootPath;
        // $option = sprintf('--path="%s"', $rootPath);
        // return $option;
    }

    protected function url(): string
    {
        $host = $this->host;
        $url = $host->get('wpcli_domain', '');
        if (empty($url)) {
            return '';
        }
        return $url;
        // $option = sprintf('--url="%s"', $url);
        // return $option;
    }

    public function cacheWarmCommand(): string
    {
        $host = $this->host;

        $url = $host->get('wpcli_domain', '');
        if (empty($url)) {
            throw error('No domain name set for current host. "wpcli_domain" empty.');
        }

        $scheme = $host->get('wpcli_scheme', '');
        if (empty($scheme) || ($scheme !== 'http' && $scheme !== 'https')) {
            throw error('Invalid scheme set for current host. "wpcli_scheme" empty or not http/https.');
        }

        $tmp = sys_get_temp_dir();
        if (!is_dir($tmp)) {
            throw error('No temporary directory could be found.');
        }

        $wget = whichLocal('wget');
        // --nocache  --limit-rate=1024k
        $command = "$wget -e robots=off -nv --ignore-length --no-check-certificate --directory-prefix=\"$tmp/wget\" --spider --recursive --no-directories --domains=$url --content-disposition --reject-regex \"(.*)\?(.*)\"  $scheme://$url/";
        //wget --directory-prefix=/tmp --spider --recursive --no-directories --domains=www.thecleanbedroom.com --content-disposition --reject-regex "(.*)\?(.*)" --limit-rate=1024k https://www.thecleanbedroom.com/
        warning($command);
        return $command;
        runLocally($command, ['real_time_output' => true, 'timeout' => 0, 'idle_timeout' => 0]);
    }

    public function command(string $command): string
    {
        $host = $this->host;
        $wp = whichContextual('wp', $host);
        $command = sprintf('%s %s --path="%s" --url="%s"', $wp, $command,  $this->sitePath($host), $this->url($host));
        return $command;
    }

    /* ----- Permissions ----- */
    public function chmodFilesCommand(string $path, string $filePerms = 'u=rw,g=r,o=r', array $excludes = []): string
    {
        $find = which('find');
        $test = which('test');
        $chmod = which('chmod');

        $exclude = '';
        if (!empty($excludes)) {
            $excludes = array_map(function ($dir) use ($path) {
                return sprintf('"%s/%s"', $path, $dir);
            }, $excludes);
            $exclude = '\( -path ';
            $exclude .= implode(' -o -path ', $excludes);
            $exclude .= ' \) -prune -o';
        }

        //$command = "$test -d $path/. && $find $path $exclude -type f -exec $chmod $filePerms '{}' \;";
        $command = sprintf('%s -d "%s/." && %s -L "%s" %s -type f -exec %s %s \'{}\' \;', $test, $path, $find, $path, $exclude, $chmod, $filePerms);
        return $command;
    }

    public function chmodDirectoryCommand(string $path, string $directoryPerms = 'u=rwx,g=rx,o=rx', array $excludes = []): string
    {
        $test = which('test');
        $find = which('find');
        $chmod = which('chmod');

        $exclude = '';
        if (!empty($excludes)) {
            $excludes = array_map(function ($dir) use ($path) {
                return sprintf('"%s/%s"', $path, $dir);
            }, $excludes);
            $exclude = '\( -path ';
            $exclude .= implode(' -o -path ', $excludes);
            $exclude .= ' \) -prune -o';
        }

        $command = "$test -d $path && $find $path $exclude -type d -exec $chmod $directoryPerms '{}' \;";
        $command = sprintf('%s -d "%s/." && %s -L "%s" %s -type d -exec %s %s \'{}\' \;', $test, $path, $find, $path, $exclude, $chmod, $directoryPerms);
        return $command;
    }

    protected function validateWordpress(): void
    {
        $user = get('http_user', false);
        if (!$user) {
            throw error('http_user is not set');
        }
        $path = $this->sitePath();
        if (!test(sprintf('[ -d "%s" ]', $path))) {
            throw error('Wordpress path not found');
        }
        if (!test(sprintf('[ -f "%s/wp-config.php" ] || [ -d "%s/wp-content" ] || [ -f "%s/wp-login.php" ]', $path, $path, $path))) {
            throw error(sprintf('Path "%s" does not seem like a wordpress directory', $path));
        }
    }

    public function resetSourcePermissions(): void
    {
        $this->validateWordpress();

        $runOptions = ['timeout' => 0, 'idle_timeout' => 0];

        $user = get('http_user', false);
        $path = $this->sitePath();
        $dirPerms = get('wpcli_dir_permissions', 'u=rwx,g=rx,o=rx');
        $filePerms = get('wpcli_file_permissions', 'u=rw,g=r,o=r');
        $wpUploadsDirs = get('wpcli_uploads_dirs', ['wp-content/uploads', 'wp-content/uploads-hcm']);

        info(sprintf('Setting permissions for %s', $path));

        // chown
        runOnHost($this->host, sprintf('%s -R %s:%s "%s"', which('chown'), $user, $user, $path), $runOptions);
        info(sprintf('Changed owner of %s to %s:%s', $path, $user, $user));

        // remove acls
        try {
            $setafcl = which('setfacl');
            runOnHost($this->host, sprintf('%s -R -b "%s"', $setafcl, $path), $runOptions);
            info('Removed ACLs');
        } catch (\Throwable $e) {
            warning('setfacl not found. Skipping ACL removal');
        }

        // chmod dirs
        runOnHost($this->host, $this->chmodDirectoryCommand($path, $dirPerms, $wpUploadsDirs), $runOptions);
        info(sprintf('Changed permissions of %s directories to %s', $path, $dirPerms));

        // chmod files
        runOnHost($this->host, $this->chmodFilesCommand($path, $filePerms, $wpUploadsDirs), $runOptions);
        info(sprintf('Changed permissions of %s site files to %s', $path, $filePerms));

        // chmod wp-config.php
        $wpConfigPath = sprintf('%s/wp-config.php', $path);
        runOnHost($this->host, sprintf('%s u=r "%s"', which('chmod'), $wpConfigPath), $runOptions);
        info(sprintf('Changed permissions of %s to u=r', $wpConfigPath));

        info('Source permissions set');
    }

    public function resetUploadsPermissions(): void
    {
        $this->validateWordpress();

        $path = $this->sitePath();
        $runOptions = ['timeout' => 0, 'idle_timeout' => 0];
        $wpUploadsDirs = get('wpcli_uploads_dirs', ['wp-content/uploads', 'wp-content/uploads-hcm']);
        if (empty($wpUploadsDirs)) {
            warning('No uploads directories set');
            return;
        }

        // chmod uploads dirs
        foreach ($wpUploadsDirs as $dir) {
            $uploads = $path . '/' . $dir;
            if (test(sprintf('[ -d "%s" ]', $uploads))) {
                $dirUploadsPerms = get('wpcli_uploads_dir_permissions', 'u=rwx,g=rwx,o=rx');
                runOnHost($this->host, $this->chmodDirectoryCommand($uploads, $dirUploadsPerms), $runOptions);
                info(sprintf('Changed permissions of %s directories to %s', $uploads, $dirUploadsPerms));

                $fileUploadsPerms = get('wpcli_uploads_file_permissions', 'u=rw,g=rw,o=r');
                runOnHost($this->host, $this->chmodFilesCommand($uploads, $fileUploadsPerms), $runOptions);
                info(sprintf('Changed permissions of %s files to %s', $uploads, $fileUploadsPerms));
            } else {
                warning(sprintf('Upload directory %s not found', $uploads));
            }
        }
        info('Upload permissions set');
    }
}

task('wp', function () {
    if (!input()->hasOption('command') || empty(input()->getOption('command'))) {
        throw error('Wp command requires option command. For example dep wp --command="cli version".');
    }

    $wpcli = new WordpressCli(currentHost());
    $command = $wpcli->command(input()->getOption('wp'));
    run($command, ['real_time_output' => true]);
})->desc('Run a wp cli command');

task('wp:cache:flush', function () {
    $wpcli = new WordpressCli(currentHost());
    $command = $wpcli->command('cache flush');
    run($command);
})->desc('Clear wordpress cache');

task('wp:cache:warm', function () {
    $wpcli = new WordpressCli(currentHost());
    $command = $wpcli->cacheWarmCommand();
    runLocally($command, ['real_time_output' => true, 'timeout' => 0, 'idle_timeout' => 0]);
})->desc('Warm external edge cache cache');

task('wp:as:clean', function () {
    $wpcli = new WordpressCli(currentHost());
    $command = $wpcli->command('action-scheduler clean --status=complete,failed,canceled --before="2 days ago"');
    run($command);
})->desc('Clear action scheduler logs that are complete, failed, or cancelled older than 2 days');

task('wp:transient:flush', function () {
    $wpcli = new WordpressCli(currentHost());
    $command = $wpcli->command('transient delete --all');
    run($command);
})->desc('Purge wp transients');

task('wp:flushall', function () {
    invoke('wp:transient:flush');
    invoke('wp:cache:flush');
})->desc('Purge the transients, wp cache');

task('wp:perms:reset-source', function () {
    $wpcli = new WordpressCli(currentHost());
    $wpcli->resetSourcePermissions();
})->desc('Sets wordpress source file/directory permissions');

task('wp:perms:reset-uploads', function () {
    $wpcli = new WordpressCli(currentHost());
    $wpcli->resetUploadsPermissions();
})->desc('Sets wordpress uploads file/directory permissions');

task('wp:perms:reset', function () {
    invoke('wp:perms:reset-source');
    invoke('wp:perms:reset-uploads');
})->desc('Sets wordpress source & uploads file/directory permissions');
