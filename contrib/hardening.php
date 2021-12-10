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
before('deploy:publish', 'deploy:harden');
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
        $test = which('test');
        $chmod = which('chmod');
        $command = "$test -d $path && $chmod -R $filePerms $path";
        run($command);
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
    unharden('{{release_path}}');
})->desc('Hardens site permissions');

/* ----------------- Task Overrides ----------------- */
// TODO - Change these to use before hook

// Needs to be overridden to account for hardened file/directory permissions
task('deploy:cleanup', function () {
    $releases = get('releases_list');
    $keep = get('keep_releases');
    $sudo = get('cleanup_use_sudo') ? 'sudo' : '';
    $runOpts = [];

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

        run("$sudo rm -rf {{deploy_path}}/releases/$release", $runOpts);
    }

    run("cd {{deploy_path}} && if [ -e release ]; then rm release; fi", $runOpts);
})->desc('Cleanups old releases after unhardening files');

task('rollback', function () {
    cd('{{deploy_path}}');

    $currentRelease = basename(run('readlink {{current_path}}'));
    $candidate = get('rollback_candidate');

    writeln("Current release is <fg=red>$currentRelease</fg=red>.");

    if (!test("[ -d releases/$candidate ]")) {
        throw new \RuntimeException(parse("Release \"$candidate\" not found in \"{{deploy_path}}/releases\"."));
    }
    if (test("[ -f releases/$candidate/BAD_RELEASE ]")) {
        writeln("Candidate <fg=yellow>$candidate</> marked as <error>bad release</error>.");
        if (!askConfirmation("Continue rollback to $candidate?")) {
            writeln('Rollback aborted.');
            return;
        }
    }
    writeln("Rolling back to <info>$candidate</info> release.");

    // Unharden permissions before removal
    $server = new ServerHardening();
    $server->unharden("{{deploy_path}}/releases/$currentRelease");

    // Symlink to old release.
    run("{{bin/symlink}} releases/$candidate {{current_path}}");

    // Mark release as bad.
    $timestamp = timestamp();
    run("echo '$timestamp,{{user}}' > releases/$currentRelease/BAD_RELEASE");

    writeln("<info>rollback</info> to release <info>$candidate</info> was <success>successful</success>");
})->desc('Rollbacks to the previous release');
