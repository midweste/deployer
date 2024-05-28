<?php

namespace Deployer;

require_once __DIR__ . '/common.php';

require_once __DIR__ . '/../contrib/clearserverpaths.php';
require_once __DIR__ . '/../contrib/filetransfer.php';
require_once __DIR__ . '/../contrib/hardening.php';
require_once __DIR__ . '/../contrib/mysql.php';
require_once __DIR__ . '/../contrib/wordpresscli.php';

add('recipes', ['cloudpanel']);

/**
 * Siteground configuration
 */
set('shared_dirs', ['wp-content/uploads']);
set('writable_dirs', ['wp-content/uploads']);

/* ----------------- filesharden ----------------- */
// for task 'deploy:harden'
set('harden_dir_permissions', 'u=rx,g=rx,o=rx');
set('harden_file_permissions', 'u=r,g=r,o=r');
/* ----------------- clear_server_paths ----------------- */
set('clear_server_paths', []);

/**
 * Deploy task
 */
// desc('Prepares a new release');
// task('deploy:prepare', [
//     'deploy:info',
//     'deploy:setup',
//     'deploy:lock',
//     'deploy:release',
//     'deploy:update_code',
//     'deploy:shared',
//     'deploy:writable',
// ]);

// desc('Publishes the release');
// task('deploy:publish', [
//     'deploy:symlink',
//     'deploy:unlock',
//     'deploy:cleanup',
//     'deploy:success',
// ]);

task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'deploy:clear_paths',
    'deploy:harden',
    'deploy:publish',
    'cp:purge'
])->desc('Deploys your project');

/**
 * Hooks
 */
before('deploy:cleanup', function () {
    invoke('deploy:unharden');
})->desc('Unharden previous site releases');

after('deploy:harden', function () {
    invoke('deploy:writablehardened');
})->desc('Apply writable permissions to files/folders in harden_writable_files');

after('deploy:failed', function () {
    invoke('deploy:unlock');
    invoke('deploy:unharden');
})->desc('Unlock after deploy:failed and unharded failed release');
after('deploy:symlink', 'deploy:clear_server_paths');

task('pull-all', [
    'db:pull-replace',
    'files:pull',
])->desc('Pull db from a remote stage, replaces instances of domain in db, and pulls writable files');

// task('cp', function () {
//     $wpcli = new WordpressCli(currentHost());
//     $command = $wpcli->command('cp');
//     run($command, ['real_time_output' => true]);
// })->desc('Show the siteground cli options');

task('cp:purge:transient', function () {
    $wpcli = new WordpressCli(currentHost());
    $command = $wpcli->command('transient delete --all');
    run($command);
})->desc('Purge wp transients');

task('cp:purge', function () {
    invoke('cp:purge:transient');
    invoke('wp:cache:flush');
    // invoke('sg:purge:memcached');
    // invoke('sg:purge:dynamic');
})->desc('Purge the transients, wp cache, and Siteground dynamic and memcached caches');

// task('sg:purge:dynamic', function () {
//     $wpcli = new WordpressCli(currentHost());
//     $command = $wpcli->command('sg purge');
//     run($command);
// })->desc('Purge the Siteground dynamic cache');

// task('sg:purge:memcached', function () {
//     $wpcli = new WordpressCli(currentHost());
//     $command = $wpcli->command('sg purge memcached');
//     run($command);
// })->desc('Purge the Siteground memcached cache');
