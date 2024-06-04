<?php

declare(strict_types=1);

namespace Deployer;

use Deployer\Host\Host;
use Deployer\Host\HostCollection;
use Deployer\Host\Localhost;

/* ----------------- Helper Functions ----------------- */

/**
 * Which ran locally
 *
 * @param string $name
 * @return void
 */
function whichLocal(string $name): string
{
    $nameEscaped = escapeshellarg($name);

    // Try `command`, should cover all Bourne-like shells
    // Try `which`, should cover most other cases
    // Fallback to `type` command, if the rest fails
    $path = runLocally("command -v $nameEscaped || which $nameEscaped || type -p $nameEscaped");
    if (empty($path)) {
        throw new \RuntimeException("Can't locate [$nameEscaped] - neither of [command|which|type] commands are available");
    }

    // Deal with issue when `type -p` outputs something like `type -ap` in some implementations
    return trim(str_replace("$name is", "", $path));
}

/**
 * Copy of which but ran contextually (local or remote)
 *
 * @param string $name
 * @return void
 */
function whichContextual(string $name, Host $host): string
{
    return ($host instanceof Localhost) ? whichLocal($name) : which($name);
}


/**
 * Executes given command on contextual host.
 *
 * Examples:
 *
 * ```php
 * run('echo hello world');
 * run('cd {{deploy_path}} && git status');
 * run('password %secret%', secret: getenv('CI_SECRET'));
 * run('curl medv.io', timeout: 5);
 * ```
 *
 * ```php
 * $path = run('readlink {{deploy_path}}/current');
 * run("echo $path");
 * ```
 *
 * @param string $command Command to run on remote host.
 * @param array|null $options Array of options will override passed named arguments.
 * @param int|null $timeout Sets the process timeout (max. runtime). The timeout in seconds (default: 300 sec; see {{default_timeout}}, `null` to disable).
 * @param int|null $idle_timeout Sets the process idle timeout (max. time since last output) in seconds.
 * @param string|null $secret Placeholder `%secret%` can be used in command. Placeholder will be replaced with this value and will not appear in any logs.
 * @param array|null $env Array of environment variables: `run('echo $KEY', env: ['key' => 'value']);`
 *
 * @throws Exception|RunException|TimeoutException
 */
function runContextually(Host $host, string $command, ?array $options = [], ?int $timeout = null, ?int $idle_timeout = null, ?string $secret = null, ?array $env = null, ?string $shell = null): string
{
    if ($host instanceof Localhost) {
        return runLocally($command, $options, $timeout, $idle_timeout, $secret, $env, $shell);
    }
    return run($command, $options, $timeout, $idle_timeout, $secret, $env);
}

function hostFromAlias(string $alias): Host
{
    $hosts = Deployer::get()->hosts;
    foreach ($hosts as $host) {
        $hostAlias = $host->getAlias();
        if (trim(strtolower($hostAlias)) == trim(strtolower($alias))) {
            return $host;
        }
    }
    throw new \RuntimeException("$alias alias is not defined");
}

function hostFromStage(string $stage): Host
{
    $hosts = Deployer::get()->hosts;
    foreach ($hosts as $host) {
        $hostStage = $host->get('stage');
        if (trim(strtolower($stage)) == trim(strtolower($hostStage))) {
            return $host;
        }
    }
    throw new \RuntimeException("$stage stage is not defined");
}

function hostCurrentDir(Host $host): string
{
    $local = ($host instanceof Localhost) ? true : false;
    $deployPath = parse($host->getDeployPath());
    return ($local) ? $deployPath : $deployPath . '/current';
}

function hostLocalhost(): Host
{
    $hosts = Deployer::get()->hosts;
    foreach ($hosts as $host) {
        if ($host instanceof Localhost) {
            return $host;
        }
    }
    throw new \RuntimeException("Localhost is not defined");
}

function hostHasLabel(Host $host, string $label): bool
{
    $labels = $host->getLabels();
    return (isset($labels[$label])) ? true : false;
}

function hosts(): HostCollection
{
    return Deployer::get()->hosts;
}

function hostsAreRemote(Host $host1, Host $host2): bool
{
    return (!$host1 instanceof Localhost && !$host2 instanceof Localhost) ? true : false;
}

function hostsOnSameServer(Host $host1, Host $host2): bool
{
    $host1Uri = $host1->getRemoteUser() . '@' . $host1->getHostname();
    $host2Uri = $host2->getRemoteUser() . '@' . $host2->getHostname();
    if (trim(strtolower($host1Uri)) == trim(strtolower($host2Uri))) {
        return true;
    }
    return false;
}

function hostsAreSame(Host $host1, Host $host2): bool
{
    $same = hostsOnSameServer($host1, $host1);
    $deploy1 = $host1->getDeployPath();
    $deploy2 = $host2->getDeployPath();
    return ($same && $deploy1 === $deploy2) ? true : false;
}
