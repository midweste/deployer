<?php
/*
## Installing

Add to your _deploy.php_

```php
require 'contrib/hardening.php';
```

## Configuration
- `harden_dir_permissions`, Permission to set directorys to when hardening. Defaults to u=rx,g=rx,o=rx
- `harden_file_permissions` Permission to set files to when hardening. Defaults to u=r,g=r,o=r

## Usage

Set hardened permissions on source files and folders

```php
before('deploy:publish', 'deploy:harden'); // Harden permissions before releasing
before('deploy:cleanup', 'deploy:unharden'); // Need to unharden permissions to allow old release removal
after('deploy:failed', 'deploy:unharden'); // Need to unharded permissions to remove hanging release
```

 */

namespace Deployer;

set('harden_dir_permissions', 'u=rx,g=rx,o=rx');
set('harden_file_permissions', 'u=r,g=r,o=r');

class ServerHardening
{

    public function chmodFilesCommand(string $path, string $filePerms = 'u=r,g=r,o=r'): string
    {
        $find = which('find');
        $test = which('test');
        $chmod = which('chmod');

        $command = "$test -d $path/. && $find $path -type f -exec $chmod $filePerms '{}' \;";
        return $command;
    }

    public function chmodDirectoryCommand(string $path, string $directoryPerms = 'u=rx,g=rx,o=rx'): string
    {
        $find = which('find');
        $test = which('test');
        $chmod = which('chmod');

        $command = "$test -d $path && $find $path -type d -exec $chmod $directoryPerms '{}' \;";
        return $command;
    }

    public function harden(string $path, string $directoryPerms, string $filePerms): void
    {
        $dcommand = $this->chmodDirectoryCommand($path, $directoryPerms);
        $fcommand = $this->chmodFilesCommand($path, $filePerms);
        run("$dcommand && $fcommand");
    }

    public function unharden(string $path, string $filePerms = 'u+rwx,g+rwx'): void
    {
        //$test = which('test');
        $chmod = which('chmod');
        if (test("[ -d $path ]")) {
            $command = "$chmod -R $filePerms $path";
            run($command);
        }
    }
}

function harden(string $path): void
{
    $dirPerms = get('harden_dir_permissions', 'u=rx,g=rx,o=rx');
    $filePerms = get('harden_file_permissions', 'u=r,g=r,o=r');

    $server = new ServerHardening;
    $server->harden($path, $dirPerms, $filePerms);
}

function unharden(string $path): void
{
    $server = new ServerHardening;
    $server->unharden($path);
}

task('deploy:harden', function () {
    harden('{{release_path}}');
})->desc('Hardens site permissions');

task('deploy:unharden', function () {

    // cleanup hanging release
    unharden("{{deploy_path}}/release");

    $releases = get('releases_list');
    $keep = get('keep_releases');
    // $sudo = get('unharden_use_sudo') ? 'sudo' : '';

    if ($keep === -1) {
        // Keep unlimited releases.
        return;
    }

    while ($keep > 0) {
        array_shift($releases);
        --$keep;
    }

    foreach ($releases as $release) {
        // Unharden permissions before removal
        unharden("{{deploy_path}}/releases/$release");
    }
})->desc('Unhardens old releases before deploy:cleanup to allow removal of read only files/dirs');
