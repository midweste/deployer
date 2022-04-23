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

option('wp', null, InputOption::VALUE_REQUIRED, 'Command to execute via wp cli');

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

        $option = sprintf('--path="%s"', $rootPath);
        return $option;
    }

    protected function url(): string
    {
        $host = $this->host;
        $url = $host->get('wpcli_domain', '');
        if (empty($url)) {
            return '';
        }
        $option = sprintf('--url="%s"', $url);
        return $option;
    }

    public function cacheWarm()
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
        // --nocache ?
        $command = "$wget -e robots=off -nv --ignore-length --no-check-certificate --directory-prefix=\"$tmp/wget\" --spider --recursive --no-directories --domains=$url --content-disposition --reject-regex \"(.*)\?(.*)\" --limit-rate=1024k $scheme://$url/";
        //wget --directory-prefix=/tmp --spider --recursive --no-directories --domains=www.thecleanbedroom.com --content-disposition --reject-regex "(.*)\?(.*)" --limit-rate=1024k https://www.thecleanbedroom.com/
        warning($command);
        runLocally($command, ['real_time_output' => true, 'timeout' => 0, 'idle_timeout' => 0]);
    }

    public function command(string $command): string
    {
        $host = $this->host;
        $command = sprintf('wp %s %s %s', $command,  $this->sitePath($host), $this->url($host));
        return $command;
    }
}

task('wp', function () {
    if (!input()->hasOption('wp') || empty(input()->getOption('wp'))) {
        throw error('Wp command requires option wp. For example dep wp --wp="cli version".');
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
    $wpcli->cacheWarm();
})->desc('Warm external edge cache cache');



// function wpcliSitePath(Host $host): string
// {
//     $deployPath = ($host instanceof Localhost) ? $host->getDeployPath() : $host->get('current_path');
//     $root = $host->get('wpcli_webroot', '');
//     $rootPath = (!empty($root)) ? $deployPath . '/' . $root : $deployPath;
//     if (empty($rootPath)) {
//         return '';
//     }

//     $option = sprintf('--path="%s"', $rootPath);
//     return $option;
// }

// function wpcliUrl(Host $host): string
// {
//     $url = $host->get('wpcli_domain', '');
//     if (empty($url)) {
//         return '';
//     }
//     $option = sprintf('--url="%s"', $url);
//     return $option;
// }

// function wpcliCommand(Host $host, string $command): string
// {
//     $command = sprintf('wp %s %s %s', $command,  wpcliSitePath($host), wpcliUrl($host));
//     return $command;
// }
