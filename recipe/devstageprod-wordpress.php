<?php

namespace Deployer;

require_once __DIR__ . '/devstageprod.php';
require_once __DIR__ . '/../contrib/hardening.php';
require_once __DIR__ . '/../contrib/wordpresscli.php';

add('recipes', ['devstageprod-wordpress']);

/**
 * Wordpress configuration
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

// desc('Deploys your project');
// task('deploy', [
//     'deploy:prepare',
//     'deploy:publish',
// ]);

task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'deploy:clear_paths',
    'deploy:harden',
    'deploy:publish',
])->desc('Deploys your project');

/**
 * Hooks
 */

/* ----------------- hardening ----------------- */
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

/* ----------------- redefined db commands ----------------- */
task('db:replace', function () {
    $host = currentHost();
    $localhost = hostLocalhost();
    $hostMysqlDomain = $host->get('mysql_domain');
    $localhostMysqlDomain = $localhost->get('mysql_domain');

    $wpcli = new WordpressCli($host);
    $mysql = new Mysql();

    // always replace the current host domain with the localhost domain
    writeln("Replacing domain: '{$hostMysqlDomain}' with '{$localhostMysqlDomain}'");
    $mysql->findReplace($host, $localhost);

    $dbHost = $host->get('mysql_host');
    $dbName = $host->get('mysql_name');
    $dbUser = $host->get('mysql_user');
    $dbPass = $host->get('mysql_pass');
    $dbPort = $host->get('mysql_port', 3306);
    $blogs = $wpcli->multisiteBlogs($dbHost, $dbName, $dbUser, $dbPass, $dbPort);
    if (empty($blogs)) {
        return;
    }

    info('Wordpress Multisite detected');
    foreach ($blogs as $blogId => $domain) {
        if ($domain === $hostMysqlDomain) {
            continue;
        }
        $localSubdomain = str_replace('.', '-', $domain) . '.' . $localhostMysqlDomain;

        $host->set('mysql_domain', $domain);
        $localhost->set('mysql_domain', $localSubdomain);

        writeln("Replacing domain: '$domain' with '$localSubdomain'");
        $mysql->findReplace($host, $localhost);
    }

    // restore the original values
    $host->set('mysql_domain', $hostMysqlDomain);
    $localhost->set('mysql_domain', $localhostMysqlDomain);
})->desc('Replace the host domain with the localhost domain in the local database');

/* ----------------- staging ----------------- */
task('staging:db:replace', function () {
    $mysql = new Mysql();
    $mysql->findReplace(currentHost(), hostFromStage('staging'));
})->desc('Truncate staging db, pull db from a production, find/replace production with staging domain');

/* ----------------- wordpresscli ----------------- */
after('deploy:publish', 'wp:cache:flush');

after('files:pull', function () {
    $host = hostLocalhost();
    $wpcli = new WordpressCli($host);
    $command = $wpcli->command('cache flush');
    runOnHost($host, $command);
});

after('db:pull', function () {
    $host = hostLocalhost();
    $wpcli = new WordpressCli($host);
    $command = $wpcli->command('cache flush');
    runOnHost($host, $command);
});

after('db:pull-replace', function () {
    $host = hostLocalhost();
    $wpcli = new WordpressCli($host);
    $command = $wpcli->command('cache flush');
    runOnHost($host, $command);
});

after('staging:files:pull', function () {
    $host = hostFromStage('staging');
    $wpcli = new WordpressCli($host);
    $command = $wpcli->command('cache flush');
    runOnHost($host, $command);
});

after('staging:db:pull-replace', function () {
    $host = hostFromStage('staging');
    $wpcli = new WordpressCli($host);
    $command = $wpcli->command('cache flush');
    runOnHost($host, $command);
});
