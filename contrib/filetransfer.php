<?php
/*
## Installing

Add to your _deploy.php_

```php
require 'contrib/filetransfer.php';
```

## Configuration
- `filetransfer_rsync_switches`, Switches for rsync file transfer
- `filetransfer_rsync_excludes` Array of excludes for rsync

## Usage

Transfer shared writable files via rsync between server instances

```php

```

 */

namespace Deployer;

use Deployer\Host\Host;
use Deployer\Host\Localhost;

set('filetransfer_rsync_switches', '-rlztv --delete'); // '-rlztv --progress --size-only --ipv4 --delete --ignore-missing-args'
set('filetransfer_rsync_excludes', []);

class FileTransfer
{

    protected function rsyncCommand(Host $source, string $sourcePath, Host $destination, string $destinationPath): string
    {
        $rsync = whichLocal('rsync');
        $switches = get('filetransfer_rsync_switches', '-rlztv --delete --dry-run');

        $rsyncExcludes = get('filetransfer_rsync_excludes', []);
        $excludes = '';
        if (!empty($rsyncExcludes)) {
            foreach ($rsyncExcludes as $exclude) {
                $excludes .= sprintf(' --exclude "%s"', $exclude);
            }
        }

        // source
        $sourceUri = $source->getRemoteUser() . '@' . $source->getHostname() . ':' . parse($sourcePath);
        if (!is_null($source->getPort())) {
            $sourceUri = '-e "ssh -p ' . $source->getPort() . '" ' . $sourceUri;
        }
        if ($source instanceof Localhost || hostsOnSameServer($source, $destination)) {
            $sourceUri = parse($sourcePath);
        }

        // destination
        $destinationUri = $destination->getRemoteUser() . '@' . $destination->getHostname() . ':' . parse($destinationPath);
        if (!is_null($destination->getPort())) {
            $destinationUri = '-e "ssh -p ' . $destination->getPort() . '" ' . $destinationUri;
        }
        if ($destination instanceof Localhost || hostsOnSameServer($source, $destination)) {
            $destinationUri = parse($destinationPath);
        }

        $command = "$rsync $switches $excludes $sourceUri $destinationUri";
        return $command;
    }

    public function pullSharedWritable(Host $source, Host $destination): void
    {
        if (hostsAreSame($source, $destination)) {
            throw error("Hosts source and destination cannot be the same host when pulling files");
        }
        if (hostIsLocalhost($source)) {
            throw error("Source host cannot be localhost");
        }
        if (hostIsProduction($destination)) {
            throw error("Destination host cannot be production");
        }

        $writable = get('writable_dirs', []);
        $shared = get('shared_dirs', []);
        $sharedWritable = array_intersect($shared, $writable);

        if (empty($sharedWritable)) {
            writeln('<error>No shared writable directories are defined</error>');
            return;
        }

        foreach ($sharedWritable as $dir) {
            $dir = parse($dir);
            $sourceAbsPath = hostCurrentDir($source) . '/' . $dir . '/';
            $destAbsPath = hostCurrentDir($destination) . '/' . $dir . '/';

            if (test('[ ! -d ' . $sourceAbsPath . ' ]')) {
                warning($sourceAbsPath . ' does not exist on source.');
                continue;
            }

            $rsyncCommand = $this->rsyncCommand($source, $sourceAbsPath, $destination, $destAbsPath);
            runOnHost(hostLocalhost(), $rsyncCommand, ['real_time_output' => false, 'timeout' => 0, 'idle_timeout' => 0]);
        }
    }
}

task('files:pull', function () {
    $server = new FileTransfer();
    $server->pullSharedWritable(currentHost(), hostLocalhost());
})->desc('Downloads shared writable folders to the localhost');
