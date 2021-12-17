<?php
/*
## Installing

Add to your _deploy.php_

```php
require 'contrib/staging.php';
```

## Configuration
- ``,

## Usage

Sets up a host as staging host that can pull files/db from production

```php

```

 */

namespace Deployer;

require_once __DIR__ . '/mysql.php';
require_once __DIR__ . '/filetransfer.php';

task('staging:files:pull', function () {
    $files = new FileTransfer();
    $files->pullSharedWritable(currentHost(), hostFromStage('staging'));
})->desc('Remove writable staging directories, copy writable directories from production to staging');

task('staging:db:pull-replace', function () {
    $mysql = new Mysql();
    $mysql->pullReplace(currentHost(), hostFromStage('staging'));
})->desc('Truncate staging db, pull db from a production, find/replace production with staging domain');

task('staging:pull-all', [
    'staging:files:pull',
    'staging:db:pull-replace'
])->desc('Copy writable directories from production to staging and truncate staging db, pull db from a production, find/replace production with staging domain');
