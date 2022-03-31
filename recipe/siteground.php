<?php

namespace Deployer;

require_once __DIR__ . '/common.php';

require_once __DIR__ . '/../contrib/clearserverpaths.php';
require_once __DIR__ . '/../contrib/hardening.php';
require_once __DIR__ . '/../contrib/pause.php';
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
set('pause_seconds', 5);

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
    'wp:cache:flush',
    'deploy:pause',
    'deploy:clear_server_paths',
])->desc('Deploys your project');

/**
 * Hooks
 */
before('deploy:cleanup', 'deploy:unharden');
after('deploy:failed', function () {
    invoke('deploy:unlock');
    invoke('deploy:unharden');
});
