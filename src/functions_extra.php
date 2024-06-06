<?php

declare(strict_types=1);

namespace Deployer;

use Deployer\Host\Host;
use Deployer\Host\HostCollection;
use Deployer\Host\Localhost;
use function Deployer\Support\array_merge_alternate;
use function Deployer\Support\env_stringify;
use Deployer\Exception\RunException;

/* ----------------- Helper Functions ----------------- */

/**
 * Copy of which but ran contextually (local or remote) on given host
 *
 * @param string $name
 * @param Host $host
 * @return string
 */
function whichContextual(string $name, Host $host): string
{
    $nameEscaped = escapeshellarg($name);

    // Try `command`, should cover all Bourne-like shells
    // Try `which`, should cover most other cases
    // Fallback to `type` command, if the rest fails
    $path = runOnHost($host, "command -v $nameEscaped || which $nameEscaped || type -p $nameEscaped", ['debug' => false, 'verbose' => false]);
    if (empty($path)) {
        throw new \RuntimeException("Can't locate [$nameEscaped] - neither of [command|which|type] commands are available");
    }

    // Deal with issue when `type -p` outputs something like `type -ap` in some implementations
    return trim(str_replace("$name is", "", $path));
}

/**
 * Which ran locally
 *
 * @param string $name
 * @return string
 */
function whichLocal(string $name): string
{
    return whichContextual($name, hostLocalhost());
}

function sshPrefix(Host $host, Host $runOnHost, string $command): string
{
    if (!$host->get('mysql_ssh', false)) {
        return $command;
    }
    $command = str_replace("'", "\'", $command);
    return sprintf('%s %s \'%s\'', whichContextual('ssh', $runOnHost), $host->connectionString(), $command);
}

/**
 * Executes given command on a provided local or remote host.
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
 * @param bool|null $real_time_output Print command output in real-time.
 * @param bool|null $no_throw Don't throw an exception of non-zero exit code.
 *
 * @throws Exception|RunException|TimeoutException
 */
function runOnHost(Host $host, string $command, ?array $options = [], ?int $timeout = null, ?int $idle_timeout = null, ?string $secret = null, ?array $env = null, ?bool $real_time_output = false, ?bool $no_throw = false): string
{
    $namedArguments = [];
    foreach (['timeout', 'idle_timeout', 'secret', 'env', 'real_time_output', 'no_throw'] as $arg) {
        if ($$arg !== null) {
            $namedArguments[$arg] = $$arg;
        }
    }
    $options = array_merge($namedArguments, $options);
    $run = function ($command, $options = []) use ($host): string {
        // $host = currentHost();

        $command = parse($command);
        $workingPath = get('working_path', '');

        if (!empty($workingPath)) {
            $command = "cd $workingPath && ($command)";
        }

        $env = array_merge_alternate(get('env', []), $options['env'] ?? []);
        if (!empty($env)) {
            $env = env_stringify($env);
            $command = "export $env; $command";
        }

        $dotenv = get('dotenv', false);
        if (!empty($dotenv)) {
            $command = ". $dotenv; $command";
        }

        $output = '[' . $host->getRemoteUser() . '@' . $host->getHostname() . '] ' . $command;
        $debug = get('debug', false);
        if (isset($options['debug']) && $options['debug'] === false) {
            $debug = false;
        }
        $verbose = get('verbose', false);
        if (isset($options['verbose']) && $options['verbose'] === false) {
            $verbose = false;
        }

        if ($debug || $verbose) {
            writeln($output);
        }
        if ($debug === false) {
            if ($host instanceof Localhost) {
                $process = Deployer::get()->processRunner;
                $output = $process->run($host, $command, $options);
            } else {
                $client = Deployer::get()->sshClient;
                $output = $client->run($host, $command, $options);
            }
        }

        return rtrim($output);
    };

    if (preg_match('/^sudo\b/', $command)) {
        try {
            return $run($command, $options);
        } catch (RunException $exception) {
            $askpass = get('sudo_askpass', '/tmp/dep_sudo_pass');
            $password = get('sudo_pass', false);
            if ($password === false) {
                writeln("<fg=green;options=bold>run</> $command");
                $password = askHiddenResponse(" [sudo] password for {{remote_user}}: ");
            }
            $run("echo -e '#!/bin/sh\necho \"\$PASSWORD\"' > $askpass");
            $run("chmod a+x $askpass");
            $command = preg_replace('/^sudo\b/', 'sudo -A', $command);
            $output = $run(" SUDO_ASKPASS=$askpass PASSWORD=%sudo_pass% $command", array_merge($options, ['sudo_pass' => escapeshellarg($password)]));
            $run("rm $askpass");
            return $output;
        }
    } else {
        return $run($command, $options);
    }
}

function hosts(): HostCollection
{
    return Deployer::get()->hosts;
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

function hostIsLocalhost(Host $host): bool
{
    return ($host instanceof Localhost) ? true : false;
}

function hostHasLabel(Host $host, string $label): bool
{
    $labels = $host->getLabels();
    return (isset($labels[$label])) ? true : false;
}

function hostIsProduction(Host $host): bool
{
    if ($host->getAlias() == 'production') {
        return true;
    }

    if ($host->get('production', false) === true) {
        return true;
    }

    if ($host->get('branch', null) == 'production') {
        return true;
    }

    return false;
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
