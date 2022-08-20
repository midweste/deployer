<?php
/*
## Installing

Add to your _deploy.php_

```php
require 'contrib/staging.php';
```

## Configuration
- ``, See configuration for contrib/mysql.php and contrib/filetransfer.php

## Usage

Sets up a host as staging host that can pull files/db from production

```php

```

 */

namespace Deployer;

require_once __DIR__ . '/mysql.php';
require_once __DIR__ . '/filetransfer.php';

task('pull-all', [
    'db:pull-replace',
    'files:pull',
])->desc('Pull db from a remote stage, replaces instances of domain in db, and pulls writable files');

task('staging:files:pull', function () {
    $files = new FileTransfer();
    $files->pullSharedWritable(currentHost(), hostFromStage('staging'));
})->desc('Remove writable staging directories, copy writable directories from production to staging');

task('staging:db:pull-replace', function () {
    $mysql = new Mysql();
    $mysql->pullReplace(currentHost(), hostFromStage('staging'));
})->desc('Truncate staging db, pull db from a production, find/replace production with staging domain');

task('staging:pull-all', [
    'staging:db:pull-replace',
    'staging:files:pull',
])->desc('Copy writable directories from production to staging and truncate staging db, pull db from a production, find/replace production with staging domain');
