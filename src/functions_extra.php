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
function whichContextual(string $name, bool $local = true): string
{
    return ($local) ? whichLocal($name) : which($name);
}

/**
 * Execute commands based on local or remote host.
 *
 * Examples:
 *
 * ```php
 * $user = runOnHost($host, 'git config user.name');
 * runOnHost($host, "echo $user");
 * ```
 *
 * @param Host $host Host to use to determine run or runLocally
 * @param string $command Command to run on localhost.
 * @param array|null $options Array of options will override passed named arguments.
 * @param int|null $timeout Sets the process timeout (max. runtime). The timeout in seconds (default: 300 sec, `null` to disable).
 * @param int|null $idle_timeout Sets the process idle timeout (max. time since last output) in seconds.
 * @param string|null $secret Placeholder `%secret%` can be used in command. Placeholder will be replaced with this value and will not appear in any logs.
 * @param array|null $env Array of environment variables: `runLocally('echo $KEY', env: ['key' => 'value']);`
 * @param string|null $shell Shell to run in. Default is `bash -s`.
 *
 * @throws RunException
 */
function runOnHost(Host $host, string $command, ?array $options = [], ?int $timeout = null, ?int $idle_timeout = null, ?string $secret = null, ?array $env = null, ?string $shell = null): void
{
    if ($host instanceof Localhost) {
        runLocally($command, $options, $timeout, $idle_timeout, $secret, $env, $shell);
    } else {
        run($command, $options, $timeout, $idle_timeout, $secret, $env, $shell);
    }
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
