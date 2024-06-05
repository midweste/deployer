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

    public function rsyncCommand(Host $source, string $sourcePath, Host $destination, string $destinationPath): string
    {
        if (hostsAreSame($source, $destination)) {
            throw error("Hosts source and destination cannot be the same host when pulling files");
        }

        // if (hostsAreRemote($source, $destination) && !hostsOnSameServer($source, $destination)) {
        //     throw error("Hosts source and destination cannot be remote and on different servers");
        // }

        $rsync = whichContextual('rsync', $destination);
        $switches = get('filetransfer_rsync_switches', '-rlztv --delete --dry-run');

        $rsyncExcludes = get('filetransfer_rsync_excludes', []);
        $excludes = '';
        if (!empty($rsyncExcludes)) {
            foreach ($rsyncExcludes as $exclude) {
                $excludes .= " --exclude \"$exclude\"";
            }
        }

        // source
        $sourceUri = $source->getRemoteUser() . '@' . $source->getHostname() . ':' . parse($sourcePath);
        if ($source instanceof Localhost || hostsOnSameServer($source, $destination)) {
            $sourceUri = parse($sourcePath);
        }

        // destination always local
        // $destinationUri = $destination->getRemoteUser() . '@' . $destination->getHostname() . ':' . parse($destinationPath);
        // if ($destination instanceof Localhost || hostsOnSameServer($source, $destination)) {
        $destinationUri = parse($destinationPath);
        // }

        // config or port
        $sshSwitches = '';
        $sshConfigFile = '';
        // if (!is_null($source->get('config_file')) || !is_null($destination->get('config_file'))) {
        //     $configFiles = [];
        //     $configFiles[] = $source->get('config_file', '');
        //     $configFiles[] = $destination->get('config_file', '');

        //     $configFiles = array_unique(array_filter($configFiles));
        //     $config_file = sys_get_temp_dir() . '/ssh_combined_config';
        //     $sshConfigFile = 'cat ' . implode(' ', $configFiles) . ' > ' . $config_file . ' && ';
        //     $sshSwitches = "-e \"ssh -F " . $config_file . "\"";
        // } else
        if (!is_null($source->getPort())) {
            $sshSwitches = "-e \"ssh -p " . $source->getPort() . "\"";
        }
        // $options = $source->connectionOptionsString();
        // warning($options);

        $command = "$sshConfigFile $rsync $sshSwitches $switches $excludes $sourceUri $destinationUri";
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

            $rsyncCommand = $this->rsyncCommand($source, $sourceAbsPath, $destination, $destAbsPath);
            runOnHost($destination, $rsyncCommand, ['real_time_output' => false, 'timeout' => 0, 'idle_timeout' => 0]);
        }
    }
}

task('files:pull', function () {
    $server = new FileTransfer();
    $server->pullSharedWritable(currentHost(), hostLocalhost());
})->desc('Downloads shared writable folders to the localhost');
