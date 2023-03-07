<?php

namespace Deployer;

require_once __DIR__ . '/common.php';

require_once __DIR__ . '/../contrib/clearserverpaths.php';
require_once __DIR__ . '/../contrib/hardening.php';
// require_once __DIR__ . '/../contrib/pause.php';
require_once __DIR__ . '/../contrib/wordpresscli.php';

add('recipes', ['siteground']);

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
/* ----------------- pause ----------------- */
// set('pause_seconds', 5);

/**
 * Siteground bin overrides
 */
set('bin/composer', function () {
    if (test('[ -f {{deploy_path}}/.dep/composer.phar ]')) {
        return '{{bin/php}} {{deploy_path}}/.dep/composer.phar';
    }

    if (commandExist('composer')) {
        return which('composer'); // sg uses a wrapper script
    }

    warning("Composer binary wasn't found. Installing latest composer to \"{{deploy_path}}/.dep/composer.phar\".");
    run("cd {{deploy_path}} && curl -sS https://getcomposer.org/installer | {{bin/php}}");
    run('mv {{deploy_path}}/composer.phar {{deploy_path}}/.dep/composer.phar');
    return '{{bin/php}} {{deploy_path}}/.dep/composer.phar';
});

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
    'sg:purge'
])->desc('Deploys your project');

/**
 * Hooks
 */
before('deploy:cleanup', 'deploy:unharden');
after('deploy:failed', function () {
    invoke('deploy:unlock');
    invoke('deploy:unharden');
});
after('deploy:symlink', 'deploy:clear_server_paths');

task('sg', function () {
    $wpcli = new WordpressCli(currentHost());
    $command = $wpcli->command('sg');
    run($command, ['real_time_output' => true]);
})->desc('Show the siteground cli options');

task('sg:purge:transient', function () {
    $wpcli = new WordpressCli(currentHost());
    $command = $wpcli->command('transient delete --all');
    run($command);
})->desc('Purge wp transients');

task('sg:purge', function () {
    invoke('sg:purge:transient');
    invoke('wp:cache:flush');
    invoke('sg:purge:memcached');
    invoke('sg:purge:dynamic');
})->desc('Purge the transients, wp cache, and Siteground dynamic and memcached caches');

task('sg:purge:dynamic', function () {
    $wpcli = new WordpressCli(currentHost());
    $command = $wpcli->command('sg purge');
    run($command);
})->desc('Purge the Siteground dynamic cache');

task('sg:purge:memcached', function () {
    $wpcli = new WordpressCli(currentHost());
    $command = $wpcli->command('sg purge memcached');
    run($command);
})->desc('Purge the Siteground memcached cache');
