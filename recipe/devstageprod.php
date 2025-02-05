<?php

namespace Deployer;

require_once __DIR__ . '/common.php';

require_once __DIR__ . '/../contrib/clearserverpaths.php';
require_once __DIR__ . '/../contrib/filetransfer.php';
require_once __DIR__ . '/../contrib/mysql.php';
require_once __DIR__ . '/../contrib/git.php';

add('recipes', ['devstageprod']);

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

// desc('Deploys your project');
// task('deploy', [
//     'deploy:prepare',
//     'deploy:publish',
// ]);

task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'deploy:clear_paths',
    'deploy:publish',
])->desc('Deploys your project');

/**
 * Hooks
 */
after('deploy:symlink', 'deploy:clear_server_paths');

/* ----------------- git ----------------- */
after('deploy:publish', 'git:tag');

/* ----------------- staging ----------------- */
task('pull-all', [
    'db:pull-replace',
    'files:pull',
])->desc('Pull db from a remote stage, replaces instances of domain in db, and pulls writable files');

task('staging:files:pull', function () {
    $files = new FileTransfer();
    $files->pullSharedWritable(currentHost(), hostFromStage('staging'));
})->desc('Remove writable staging directories, copy writable directories from production to staging');

task('staging:db:replace', function () {
    $mysql = new Mysql();
    $mysql->findReplace(currentHost(), hostFromStage('staging'));
})->desc('Truncate staging db, pull db from a production, find/replace production with staging domain');

task('staging:db:pull-replace', function () {
    $mysql = new Mysql();
    $mysql->pull(currentHost(), hostFromStage('staging'));
    $mysql->findReplace(currentHost(), hostFromStage('staging'));
})->desc('Truncate staging db, pull db from a production, find/replace production with staging domain');

task('staging:pull-all', [
    'staging:db:pull-replace',
    'staging:files:pull',
])->desc('Copy writable directories from production to staging and truncate staging db, pull db from a production, find/replace production with staging domain');
