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

set('filetransfer_rsync_switches', '-rlztv --progress --size-only --ipv4 --delete --ignore-missing-args');
set('filetransfer_rsync_excludes', []);

class FileTransfer
{

    public function rsyncCommand(Host $source, string $sourcePath, Host $destination, string $destinationPath, bool $local = true): string
    {

        if (hostsAreSame($source, $destination)) {
            throw error("Hosts source and destination cannot be the same host when pulling files");
        }

        if (hostsAreRemote($source, $destination) && !hostsOnSameServer($source, $destination)) {
            throw error("Hosts source and destination cannot be remote and on different servers");
        }

        $rsync = whichContextual('rsync', $local);
        $switches = get('filetransfer_rsync_switches', '-rlztv --progress --size-only --ipv4 --delete --ignore-missing-args');  # --delete-after? --delete-before?

        $rsyncExcludes = get('filetransfer_rsync_excludes', []);
        $excludes = '';
        if (!empty($rsyncExcludes)) {
            foreach ($rsyncExcludes as $exclude) {
                $excludes .= " --exclude \"$exclude\"";
            }
        }

        // source
        $sourceUri = $source->getRemoteUser() . '@' . $source->getHostname() . ':' . parse($sourcePath);
        $port = '';
        if ($source instanceof Localhost || hostsOnSameServer($source, $destination)) {
            $sourceUri = parse($sourcePath);
        } else {
            $port = "-e \"ssh -p " . $source->getPort() . "\"";
            $sourceUri = $source->getRemoteUser() . '@' . $source->getHostname() . ':' . parse($sourcePath);
        }

        // destination
        if ($destination instanceof Localhost || hostsOnSameServer($source, $destination)) {
            $destinationUri = parse($destinationPath);
        } else {
            $destinationUri = $destination->getRemoteUser() . '@' . $destination->getHostname() . ':' . parse($destinationPath);
        }

        $command = "$rsync $port $switches $excludes $sourceUri $destinationUri";
        return $command;
    }

    public function pullSharedWritable(Host $source, Host $destination): void
    {
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

            if (hostsAreRemote($source, $destination)) {
                $rsyncCommand = $this->rsyncCommand($source, $sourceAbsPath, $destination, $destAbsPath, false);
                run($rsyncCommand);
            } else {
                $rsyncCommand = $this->rsyncCommand($source, $sourceAbsPath, $destination, $destAbsPath, true);
                runLocally($rsyncCommand);
            }
        }
    }
}

task('test:files:pull', function () {
    $dryrun = get('dry-run');
    set('dry-run', true);

    $server = new FileTransfer();
    writeln('Pull to localhost from remote server');
    $server->pullSharedWritable(currentHost(), hostLocalhost());

    writeln('Pull to remote server from remote server');
    $server->pullSharedWritable(currentHost(), hostFromStage('staging'));

    set('dry-run', $dryrun);
})->desc('Tests local and remote file transfer');

task('files:pull', function () {
    $server = new FileTransfer();
    $server->pullSharedWritable(currentHost(), hostLocalhost());
})->desc('Downloads shared writable folders to the localhost');
