<?php
/*
/*
## Installing

Add to your _deploy.php_

```php
require 'contrib/cliq.php';
```

## Configuration

- `cliq_webhook` – cliq incoming webhook url, **required**
  ```
  set('cliq_webhook', 'https://cliq.zoho.com/company/<company id>/api/v2/channelsbyname/<server>...');
  ```
- `cliq_text` – notification message template, markdown supported
  ```
  set('cliq_text', '_{{user}}_ deploying `{{branch}}` to *{{target}}*');
  ```
- `cliq_success_text` – success template, default:
  ```
  set('cliq_success_text', 'Deploy to *{{target}}* successful');
  ```
- `cliq_failure_text` – failure template, default:
  ```
  set('cliq_failure_text', 'Deploy to *{{target}}* failed');
  ```
- `cliq_rollback_text` – rollback template, default:
  ```
  set('cliq_failure_text', '_{{user}}_ rolled back changes on *{{target}}*');
  ```

## Usage

If you want to notify only about beginning of deployment add this line only:

```php
before('deploy', 'cliq:notify');
```

If you want to notify about successful end of deployment add this too:

```php
after('deploy:success', 'cliq:notify:success');
```

If you want to notify about failed deployment add this too:

```php
after('deploy:failed', 'cliq:notify:failure');
```

 */

namespace Deployer;

use Deployer\Utility\Httpie;

// Channel to publish to, when false the default channel the webhook will be used
set('cliq_channel', false);

// Title of project
set('cliq_title', function () {
    return get('application', 'Project');
});

// Deploy message
set('cliq_text', '_{{user}}_ deploying `{{target}}` to *{{hostname}}*');
set('cliq_success_text', 'Deploy to *{{target}}* successful');
set('cliq_failure_text', 'Deploy to *{{target}}* failed');
set('cliq_rollback_text', '_{{user}}_ rolled back changes on *{{target}}*');

class Cliq
{
    public function __construct()
    {
        $this->checkRequirements();
    }

    protected function checkRequirements()
    {
        $valid = true;
        if (!get('cliq_webhook', false)) {
            warning('No Cliq webhook configured');
            $valid = false;
        }
        if (!$valid) {
            throw error('Missing require settings for cliq. Please configure it in your deploy.php file.');
        }
    }

    public function send(object $message): string
    {
        $info = [];
        $response = Httpie::post(get('cliq_webhook'))->body(json_encode($message))->send($info);
        $status = $info['http_code'];
        if (empty($status) || $status > 204) {
            warning(sprintf('There was an error sending the notification to Cliq. Status code: %s', $status));
        }
        return $response;
    }

    public function createMessage(string $text, string $from = 'Deployer'): object
    {
        return (object) [
            'text' => $text,
            'bot' => (object) [
                'name' => $from
            ]
        ];
    }
}

task('cliq:notify', function () {
    $cliq = new Cliq();
    $cliq->send($cliq->createMessage(get('cliq_text')));
})->desc('Notifies Cliq')->once(); //->hidden();

task('cliq:notify:success', function () {
    $cliq = new Cliq();
    $cliq->send($cliq->createMessage(get('cliq_success_text')));
})->desc('Notifies Cliq about deploy finish')->once()->hidden();

task('cliq:notify:failure', function () {
    $cliq = new Cliq();
    $cliq->send($cliq->createMessage(get('cliq_failure_text')));
})->desc('Notifies Cliq about deploy failure')->once()->hidden();

task('cliq:notify:rollback', function () {
    $cliq = new Cliq();
    $cliq->send($cliq->createMessage(get('cliq_rollback_text')));
})->desc('Notifies Cliq about rollback')->once()->hidden();
