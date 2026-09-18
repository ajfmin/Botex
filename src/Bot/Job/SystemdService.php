<?php

namespace Botex\Bot\Job;

use Botex\Botex;
use Botex\Support\Config;
use Botex\Support\Log\Logger;

/**
 * The worker as a systemd unit: installed, started, restarted, removed.
 *
 * `jobs:work` is the one process a Botex bot has to keep alive, and until
 * now keeping it alive was left entirely to the operator. In practice
 * that meant `nohup`, or a screen session, or a cron line that started a
 * second copy every five minutes -- and the last of those is how an
 * install ends up with the scheduler running twice.
 *
 * **The unit name is derived from the install path, and that is the whole
 * "only one service" guarantee.** It is not configurable and not stored:
 *
 *     botex-<directory name>-<8 hex of the real path>
 *
 * Two bots on one box get two units because their paths differ. The same
 * bot gets the same unit every time, from any shell, whoever is asking --
 * so installing twice replaces one unit rather than accumulating two that
 * both run the same worker. A stored name could drift from the thing it
 * names; a derived one cannot.
 *
 * The hash is over the *resolved* path, so a symlinked and a direct route
 * to one install are one unit rather than two.
 *
 * Belt and braces, deliberately: the unit is one, and the worker it
 * starts still takes the database lease before doing anything (see
 * [[WorkerLease]]). Either alone would be enough on a good day. Together
 * they cover the case neither handles by itself -- a second worker started
 * by hand while the service is running, which systemd cannot see and the
 * lease refuses.
 *
 * Runs as a **system** unit when invoked by root and a **user** unit
 * otherwise, because a bot on a VPS is usually root and a bot on shared
 * hosting never is. Nothing here silently escalates: a non-root install
 * writes to the operator's own systemd directory, and the one extra step
 * a user unit needs to survive logout is printed rather than assumed.
 */
class SystemdService
{
    /** Every unit this class writes starts with it, so strays are findable. */
    public const PREFIX = 'botex-';

    /** Seconds systemd waits before restarting a worker that exited. */
    public const RESTART_DELAY = 10;

    /** Unit files are Linux files, whatever platform writes them. */
    private const EOL = "
";

    /** Long enough for a job in flight to finish on SIGTERM. */
    public const STOP_TIMEOUT = 30;

    private string $root;

    public function __construct(
        private Config $config,
        private Logger $log
    ) {
        $this->root = rtrim(
            (string) $config->get('paths.root', dirname(__DIR__, 3)),
            '/\\'
        );
    }

    /**
     * Why this host cannot run a service, or null when it can.
     *
     * Checked before anything is written, so the answer is one sentence
     * rather than a permission error from a directory that does not
     * exist.
     */
    public function unsupported(): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return 'This only works on Linux with systemd; this host is ' . PHP_OS_FAMILY . '.';
        }

        if (!is_dir('/run/systemd/system')) {
            return 'systemd is not running on this host (no /run/systemd/system).';
        }

        if ($this->which('systemctl') === null) {
            return 'systemctl was not found in PATH.';
        }

        if (!function_exists('proc_open')) {
            return 'proc_open() is disabled, so systemctl cannot be called.';
        }

        return null;
    }

    /**
     * This install's unit name.
     *
     * Derived, never stored. See the class note: the derivation *is* the
     * uniqueness guarantee.
     */
    public function name(): string
    {
        $path = $this->realRoot();
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', basename($path)));
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'bot';
        }

        return self::PREFIX . mb_substr($slug, 0, 24) . '-' . substr(hash('sha256', $path), 0, 8);
    }

    public function unitName(): string
    {
        return $this->name() . '.service';
    }

    /** 'system' when running as root, 'user' otherwise. */
    public function scope(): string
    {
        return $this->isRoot() ? 'system' : 'user';
    }

    /** Where this scope keeps its units. */
    public function unitDirectory(): string
    {
        if ($this->isRoot()) {
            return '/etc/systemd/system';
        }

        $home = rtrim((string) (getenv('HOME') ?: ''), '/');

        return ($home === '' ? sys_get_temp_dir() : $home) . '/.config/systemd/user';
    }

    public function unitPath(): string
    {
        return $this->unitDirectory() . '/' . $this->unitName();
    }

    public function isInstalled(): bool
    {
        return is_file($this->unitPath());
    }

    public function isActive(): bool
    {
        return $this->systemctl(['is-active', $this->unitName()])['code'] === 0;
    }

    /** Whether it comes back after a reboot. */
    public function isEnabled(): bool
    {
        return $this->systemctl(['is-enabled', $this->unitName()])['code'] === 0;
    }

    /**
     * The unit file this install would get.
     *
     * Public so it can be printed without being written -- an operator
     * who cannot or will not let the bot touch systemd can still take
     * this, read it, and install it themselves.
     */
    public function unitContents(): string
    {
        $php = $this->phpBinary();
        $root = $this->realRoot();
        $user = $this->isRoot() ? $this->serviceUser() : null;

        $lines = [
            '[Unit]',
            'Description=Botex worker for ' . $root,
            'Documentation=https://github.com/ajfmin/botex',
            // A worker that starts before the database is listening exits
            // and gets restarted, which works but fills the journal with
            // a failure that was never real.
            'After=network-online.target mysql.service mariadb.service',
            'Wants=network-online.target',
            '',
            '[Service]',
            'Type=simple',
            'WorkingDirectory=' . $root,
            'ExecStart=' . $php . ' ' . $root . '/bin/console jobs:work',
        ];

        if ($user !== null) {
            $lines[] = 'User=' . $user;
        }

        // Joined with a literal newline rather than PHP_EOL: this file is
        // always read by systemd on Linux, whatever wrote it, so a unit
        // generated on a Windows box for review is the same bytes as one
        // written on the server.
        return implode(self::EOL, [
            ...$lines,
            // Always: the worker exits on a lost lease, a database blip or
            // a deploy, and every one of those should end with it running
            // again. The delay stops a permanently broken install from
            // spinning.
            'Restart=always',
            'RestartSec=' . self::RESTART_DELAY,
            // The worker installs its own SIGTERM handler and finishes the
            // job in hand before exiting, so it is given time to.
            'KillSignal=SIGTERM',
            'TimeoutStopSec=' . self::STOP_TIMEOUT,
            'StandardOutput=journal',
            'StandardError=journal',
            'SyslogIdentifier=' . $this->name(),
            '',
            '[Install]',
            'WantedBy=' . ($this->isRoot() ? 'multi-user.target' : 'default.target'),
            '',
        ]);
    }

    /**
     * Writes the unit, reloads systemd and enables it.
     *
     * Any stray unit left by an earlier install of this same bot is
     * stopped and removed first. That is what keeps "one service per
     * install" true across a rename or a move: the name is derived from
     * the path, so moving the directory would otherwise leave the old
     * unit behind, enabled, pointing at somewhere that no longer exists.
     *
     * @return array<string> what was done, in order, for printing
     *
     * @throws ServiceException
     */
    public function install(): array
    {
        $this->refuseUnsupported();

        $done = [];

        foreach ($this->strays() as $stray) {
            $this->systemctl(['disable', '--now', $stray]);
            @unlink($this->unitDirectory() . '/' . $stray);
            $done[] = 'removed a stray unit for this install: ' . $stray;
        }

        $directory = $this->unitDirectory();

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new ServiceException('Could not create ' . $directory . '.');
        }

        if (@file_put_contents($this->unitPath(), $this->unitContents()) === false) {
            throw new ServiceException(
                'Could not write ' . $this->unitPath() . '.'
                    . ($this->isRoot() ? '' : ' Run as root for a system-wide service.')
            );
        }

        @chmod($this->unitPath(), 0644);
        $done[] = 'wrote ' . $this->unitPath();

        $this->must(['daemon-reload'], 'reload systemd');
        $done[] = 'reloaded systemd';

        $this->must(['enable', $this->unitName()], 'enable ' . $this->unitName());
        $done[] = 'enabled ' . $this->unitName() . ' (' . $this->scope() . ' scope)';

        $this->log->info('Installed the Botex worker service', [
            'unit' => $this->unitName(),
            'scope' => $this->scope(),
        ]);

        return $done;
    }

    /** @throws ServiceException */
    public function start(): void
    {
        $this->refuseUnsupported();
        $this->must(['start', $this->unitName()], 'start ' . $this->unitName());
    }

    /** @throws ServiceException */
    public function stop(): void
    {
        $this->refuseUnsupported();
        $this->must(['stop', $this->unitName()], 'stop ' . $this->unitName());
    }

    /** @throws ServiceException */
    public function restart(): void
    {
        $this->refuseUnsupported();
        $this->must(['restart', $this->unitName()], 'restart ' . $this->unitName());
    }

    /**
     * Stops it, disables it and deletes the unit.
     *
     * Never fails on a unit that is already gone: removing something that
     * is not there is the state the caller asked for.
     *
     * @throws ServiceException
     */
    public function remove(): array
    {
        $this->refuseUnsupported();

        $done = [];

        if ($this->isInstalled() || $this->isEnabled()) {
            $this->systemctl(['disable', '--now', $this->unitName()]);
            $done[] = 'stopped and disabled ' . $this->unitName();
        }

        if (is_file($this->unitPath()) && @unlink($this->unitPath())) {
            $done[] = 'deleted ' . $this->unitPath();
        }

        $this->systemctl(['daemon-reload']);
        $this->systemctl(['reset-failed', $this->unitName()]);
        $done[] = 'reloaded systemd';

        $this->log->warning('Removed the Botex worker service', ['unit' => $this->unitName()]);

        return $done;
    }

    /**
     * Everything worth printing about the unit.
     *
     * @return array{
     *     supported: bool, reason: ?string, unit: string, scope: string,
     *     path: string, installed: bool, enabled: bool, active: bool,
     *     state: string, strays: array<string>
     * }
     */
    public function status(): array
    {
        $reason = $this->unsupported();

        if ($reason !== null) {
            return [
                'supported' => false,
                'reason' => $reason,
                'unit' => $this->unitName(),
                'scope' => $this->scope(),
                'path' => $this->unitPath(),
                'installed' => false,
                'enabled' => false,
                'active' => false,
                'state' => 'unsupported',
                'strays' => [],
            ];
        }

        return [
            'supported' => true,
            'reason' => null,
            'unit' => $this->unitName(),
            'scope' => $this->scope(),
            'path' => $this->unitPath(),
            'installed' => $this->isInstalled(),
            'enabled' => $this->isEnabled(),
            'active' => $this->isActive(),
            'state' => trim($this->systemctl(['is-active', $this->unitName()])['out']) ?: 'unknown',
            'strays' => $this->strays(),
        ];
    }

    /** The last few journal lines for this unit. */
    public function logs(int $lines = 40): string
    {
        if ($this->unsupported() !== null || $this->which('journalctl') === null) {
            return '';
        }

        $command = ['journalctl', '-u', $this->unitName(), '-n', (string) max(1, $lines), '--no-pager'];

        if (!$this->isRoot()) {
            $command[] = '--user';
        }

        return $this->exec($command)['out'];
    }

    /**
     * Other Botex units pointing at this same install.
     *
     * The one thing a derived name cannot prevent on its own: rename the
     * directory and the name changes with it, leaving the unit written
     * under the old name enabled and pointing at a path that has moved.
     * Found by reading WorkingDirectory out of every botex-*.service in
     * this scope, so it catches the moved case and the renamed one alike.
     *
     * @return array<string> unit file names, never including our own
     */
    public function strays(): array
    {
        $ours = $this->unitName();
        $root = $this->realRoot();
        $strays = [];

        foreach (glob($this->unitDirectory() . '/' . self::PREFIX . '*.service') ?: [] as $file) {
            $unit = basename($file);

            if ($unit === $ours) {
                continue;
            }

            if (self::ownsRoot((string) @file_get_contents($file), $root)) {
                $strays[] = $unit;
            }
        }

        sort($strays);

        return $strays;
    }

    /**
     * Whether a unit file supervises the install at this path.
     *
     * The whole stray test, in one place and answerable from a string,
     * because the alternative -- comparing unit names -- cannot work. A
     * moved or renamed install has a different name by construction, and
     * it is precisely the unit written under the *old* name that has to
     * be found and cleared.
     */
    public static function ownsRoot(string $unitContents, string $root): bool
    {
        if (preg_match('/^WorkingDirectory=(.*)$/m', $unitContents, $matches) !== 1) {
            return false;
        }

        $normalise = static fn (string $path): string
            => rtrim(str_replace(chr(92), '/', trim($path)), '/');

        return $normalise($matches[1]) === $normalise($root);
    }

    /**
     * What a user-scope unit needs that a system one does not.
     *
     * A user unit dies when the operator logs out unless lingering is on,
     * which is the single most likely reason for "I installed it and it
     * stopped overnight". Returned rather than printed so the console
     * decides where it goes.
     */
    public function lingerHint(): ?string
    {
        if ($this->isRoot()) {
            return null;
        }

        $user = $this->serviceUser();

        return 'This is a user service, so it stops when you log out. To keep it '
            . 'running: sudo loginctl enable-linger ' . $user;
    }

    /** @throws ServiceException */
    private function refuseUnsupported(): void
    {
        $reason = $this->unsupported();

        if ($reason !== null) {
            throw new ServiceException($reason);
        }
    }

    /**
     * Runs systemctl and throws with its own words when it refuses.
     *
     * @param array<string> $arguments
     *
     * @throws ServiceException
     */
    private function must(array $arguments, string $what): void
    {
        $result = $this->systemctl($arguments);

        if ($result['code'] === 0) {
            return;
        }

        $detail = trim($result['err']) !== '' ? trim($result['err']) : trim($result['out']);

        throw new ServiceException(
            'Could not ' . $what . ': ' . ($detail === '' ? 'systemctl exited ' . $result['code'] : $detail)
                . ($this->isRoot() ? '' : PHP_EOL . ($this->lingerHint() ?? ''))
        );
    }

    /**
     * @param  array<string> $arguments
     * @return array{code:int, out:string, err:string}
     */
    private function systemctl(array $arguments): array
    {
        $command = ['systemctl'];

        // Every call in this class is about one unit in one scope, so the
        // flag rides along with the command rather than being remembered
        // by each caller.
        if (!$this->isRoot()) {
            $command[] = '--user';
        }

        return $this->exec([...$command, ...$arguments]);
    }

    /**
     * Runs a command without a shell.
     *
     * An argv array rather than a string, so a path with a space in it is
     * one argument and nothing here is quoting-sensitive.
     *
     * Protected rather than private: it and unsupported() are the two
     * seams this class can be stood in for at, which is what lets the
     * install/start/remove sequences be exercised somewhere there is no
     * systemd to exercise them against.
     *
     * @param  array<string> $command
     * @return array{code:int, out:string, err:string}
     */
    protected function exec(array $command): array
    {
        $pipes = [];

        $process = @proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->realRoot()
        );

        if (!is_resource($process)) {
            return ['code' => 127, 'out' => '', 'err' => 'could not run ' . $command[0]];
        }

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
    }

    private function which(string $binary): ?string
    {
        foreach (['/usr/bin/', '/bin/', '/usr/local/bin/', '/usr/sbin/', '/sbin/'] as $directory) {
            if (is_executable($directory . $binary)) {
                return $directory . $binary;
            }
        }

        return null;
    }

    /**
     * The PHP that will run the worker.
     *
     * PHP_BINARY, because the console is CLI-only and that is exactly the
     * interpreter the operator just used -- which matters on a host with
     * several PHP versions, where guessing `/usr/bin/php` installs a
     * service that runs on the wrong one.
     */
    private function phpBinary(): string
    {
        $binary = PHP_BINARY;

        return $binary !== '' && is_executable($binary) ? $binary : ($this->which('php') ?? 'php');
    }

    /** The account the unit runs as. */
    private function serviceUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = @posix_getpwuid(posix_geteuid());

            if (is_array($info) && ($info['name'] ?? '') !== '') {
                return (string) $info['name'];
            }
        }

        $user = (string) (getenv('USER') ?: getenv('LOGNAME') ?: '');

        return $user !== '' ? $user : 'root';
    }

    private function isRoot(): bool
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid() === 0;
        }

        return function_exists('getmyuid') && getmyuid() === 0;
    }

    /** The install path, resolved, so a symlink is not a second install. */
    private function realRoot(): string
    {
        $real = realpath($this->root);

        return rtrim(str_replace('\\', '/', $real === false ? $this->root : $real), '/');
    }

    /** For a status line: which Botex this unit belongs to. */
    public function describe(): string
    {
        return Botex::NAME . ' ' . Botex::VERSION . ' at ' . $this->realRoot();
    }
}
