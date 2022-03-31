<?php
/*
## Installing

Add to your _deploy.php_

```php
require 'contrib/pause.php';
```

## Configuration
- `pause_seconds`, How long to pause execution

## Usage

Pause during deployment

```php
before('deploy:publish', 'deploy:pause'); // Pause after publish
```

 */

namespace Deployer;

set('pause_seconds', 0);

task('deploy:pause', function () {
    sleep(get('pause_seconds', 1));
})->desc('Sleep for X seconds');
