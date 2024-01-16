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
- `harden_writeable_dir_permissions` Permission to set directories to when applying writable. Defaults to u=rwx,g=rx,o=rx
- `harden_writeable_file_permissions` Permission to set files to when applying writable. Defaults to u=rw,g=r,o=r
- `harden_writable_files` Array of absolute files/folders to apply writable to after hardening. Defaults to []

## Usage

Set hardened permissions on source files and folders

```php
before('deploy:publish', 'deploy:harden'); // Harden permissions before releasing
before('deploy:cleanup', 'deploy:unharden'); // Need to unharden permissions to allow old release removal
after('deploy:failed', 'deploy:unharden'); // Need to unharded permissions to remove hanging release
after('deploy:harden', 'deploy:writable'); // Apply writable permissions to files/folders in harden_writable_files
```

 */

namespace Deployer;

set('harden_dir_permissions', 'u=rx,g=rx,o=rx');
set('harden_file_permissions', 'u=r,g=r,o=r');
set('harden_writeable_dir_permissions', 'u=rwx,g=rx,o=rx');
set('harden_writeable_file_permissions', 'u=rw,g=r,o=r');
set('harden_writable_files', []);

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

    public function writable(string $path): void
    {
        $chmod = which('chmod');

        if (test("[ -d $path ]")) {
            $dirPerms = get('harden_writeable_dir_permissions', 'u=rwx,g=rx,o=rx');
            $command = "$chmod $dirPerms $path";
            run($command);
            return;
        }

        if (test("[ -f $path ]")) {
            $filePerms = get('harden_writeable_file_permissions', 'u=rw,g=r,o=r');
            $command = "$chmod $filePerms $path";
            run($command);
            return;
        }

        warning($path . ' is not a directory or file to make writable!');
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

function writable(string $path): void
{
    $files = get('harden_writable_files', []);

    $server = new ServerHardening;
    foreach ($files as $file) {
        $server->writable($file);
    }
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

task('deploy:writable', function () {
    $files = get('harden_writable_files', []);
    foreach ($files as $file) {
        writable($file);
    }
})->desc('Applies write permission to files/folders in harden_writable_files after hardening');
