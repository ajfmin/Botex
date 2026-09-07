<?php

namespace Botex\Support\Log;

use Botex\Bot\CurrentUpdate;

/**
 * The one logging entry point.
 *
 * Writes leveled, single-line records to a daily file under storage/logs
 * and hands anything serious enough to a LogNotifier, which tells the
 * admins in telegram.
 *
 * Every record carries context: who (the telegram user and chat, folded in
 * from the ambient update automatically), what (type, handler, extension,
 * action or job), and, when there is one, the exception with its trace.
 *
 * The writer itself must never be the thing that breaks a request, so a
 * failed write goes to error_log() and a failed notification is written to
 * the file without notifying again.
 */
class Logger
{
    /** Where each distinct call site came from, keyed by file and function. */
    private array $origins = [];

    /** Guards against a failing notification logging, which would notify. */
    private bool $notifying = false;

    /**
     * Throwables already recorded by exception().
     *
     * A handler failure is logged where its context is known (the dispatcher)
     * and then rethrown to the webhook catch, which would otherwise record the
     * same stack a second time and notify the admins twice. A WeakMap means an
     * exception that was garbage collected can never be mistaken for one that
     * was logged.
     */
    private static \WeakMap $seen;

    /**
     * @param  string  $path     directory for the daily log files
     * @param  Level   $level    the quietest entry written to the file
     * @param  int     $keepDays log files older than this are deleted; 0 keeps
     * @param  Level   $pruneAt  the level whose write triggers that sweep
     */
    public function __construct(
        private string $path,
        private Level $level = Level::Info,
        private ?CurrentUpdate $current = null,
        private ?LogNotifier $notifier = null,
        private int $keepDays = 14,
        private Level $pruneAt = Level::Critical
    ) {
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log(Level::Debug, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(Level::Info, $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log(Level::Notice, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(Level::Warning, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(Level::Error, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log(Level::Critical, $message, $context);
    }

    /**
     * Logs a throwable at the given severity, trace included.
     *
     * Wherever the exception is already in hand this beats assembling the
     * context by hand, and it is the call to use in a catch block.
     */
    public function exception(
        \Throwable $e,
        string $message = '',
        Level $level = Level::Error,
        array $context = []
    ): void {
        $this->log($level, $message !== '' ? $message : $e->getMessage(), $context + [
            'exception' => $e,
            'origin' => $this->thrownFrom($e),
        ]);

        self::seenMap()[$e] = true;
    }

    /**
     * Whether this throwable has already been logged by exception().
     *
     * Boundary catches (webhook, console) consult this so a failure that was
     * recorded deeper down, where better context was available, is written
     * and notified exactly once.
     */
    public static function seen(\Throwable $e): bool
    {
        return isset(self::seenMap()[$e]);
    }

    private static function seenMap(): \WeakMap
    {
        return self::$seen ??= new \WeakMap();
    }

    public function level(): Level
    {
        return $this->level;
    }

    public function log(Level $level, string $message, array $context = []): void
    {
        if (!$level->atLeast($this->level)) {
            return;
        }

        $record = $this->decorate($context);

        // The origin is where the log call sits, unless a throwable was
        // handed in: then the interesting frame is where it was thrown.
        if (!isset($record['origin'])) {
            $record['origin'] = $this->caller();
        }

        $this->write($level, $message, $record);

        if ($level === $this->pruneAt) {
            $this->maybePrune();
        }

        if ($this->notifier !== null && !$this->notifying) {
            $this->notify($level, $message, $record);
        }
    }

    private function notify(Level $level, string $message, array $record): void
    {
        $this->notifying = true;

        try {
            $this->notifier->send($level, $message, $record);
        } catch (\Throwable $e) {
            // Only reach the file; the flag is still set, so this write
            // cannot spawn another notification attempt.
            $this->write(Level::Error, 'Log notification failed', [
                'exception' => $e,
                'origin' => 'logger',
            ]);
        } finally {
            $this->notifying = false;
        }
    }

    /**
     * The context as written: ambient facts first, so an explicit key at
     * the call site always wins over what the request happened to carry.
     */
    private function decorate(array $context): array
    {
        $ambient = [];
        $update = $this->current?->get();

        if ($update !== null) {
            $ambient['type'] = $update->isCallback() ? 'callback' : 'message';

            $user = $update->fromId();
            $chat = $update->chatId();

            if ($user !== null) {
                $ambient['user'] = (int) $user;
            }

            if ($chat !== null) {
                $ambient['chat'] = (int) $chat;
            }
        }

        return array_filter(
            array_merge($ambient, $context),
            fn ($value) => $value !== null && $value !== ''
        );
    }

    /** One line: bracketed header, the message, then the context as JSON. */
    private function format(Level $level, string $message, array $record): string
    {
        $header = sprintf(
            '[%s] [%-8s] [%s]',
            date('Y-m-d H:i:s'),
            $level->label(),
            $record['origin'] ?? '?'
        );

        $context = $record;
        unset($context['origin']);

        if (($e = $context['exception'] ?? null) instanceof \Throwable) {
            $context['exception'] = $this->describeError($e);

            // The chain only the file can afford, and only when it exists.
            if ($e->getPrevious() !== null) {
                $context['caused_by'] = $this->describeError($e->getPrevious());
            }

            if ($level->atLeast(Level::Error)) {
                $context['trace'] = array_map(
                    fn ($frame) => ($frame['file'] ?? '[php]') . ':' . ($frame['line'] ?? 0)
                        . ' ' . ($frame['function'] ?? ''),
                    array_slice($e->getTrace(), 0, 15)
                );
            }
        }

        return $header . ' ' . $message
            . ($context ? ' ' . json_encode(
                $context,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
            ) : '')
            . PHP_EOL;
    }

    private function describeError(\Throwable $e): array
    {
        return [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ];
    }

    private function write(Level $level, string $message, array $record): void
    {
        $line = $this->format($level, $message, $record);

        try {
            if (!is_dir($this->path)) {
                @mkdir($this->path, 0775, true);
            }

            file_put_contents($this->file(), $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // A logger that throws is an outage amplifier. error_log() is
            // the last channel that cannot fail this way.
            error_log(rtrim($line));
        }
    }

    /** One file per day, so browsing a problem is never a grep of a year. */
    private function file(): string
    {
        return $this->path . '/app-' . date('Y-m-d') . '.log';
    }

    /**
     * Deletes log files older than the retention window.
     *
     * Rides on critical writes rather than needing cron, the same way
     * expired actions are pruned on webhook traffic. A critical entry is
     * rare enough that this costs almost nothing, and the file name carries
     * its own date in whole-day precision, so the comparison is a string
     * one and never stats a file.
     */
    private function maybePrune(): void
    {
        if ($this->keepDays <= 0) {
            return;
        }

        // A file is named for the local day it starts, so yesterday's file
        // survives one day past its real age. Retention is a rough window,
        // not a promise.
        $cutoff = date('Y-m-d', time() - max(1, $this->keepDays - 1) * 86400);

        foreach ((array) glob($this->path . '/app-*.log') as $file) {
            // 'app-2026-08-31.log' -> '2026-08-31'
            $day = substr(basename((string) $file), 4, 10);

            if ($day < $cutoff) {
                @unlink($file);
            }
        }
    }

    /**
     * Who called this, as extension:action, core:area:function, or a file
     * name for code outside a class.
     */
    private function caller(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25) as $frame) {
            $class = $frame['class'] ?? null;
            $file = $frame['file'] ?? null;

            // Skip straight past the logging package itself, so Log::info()
            // and $logger->info() blame the real caller alike.
            if ($class !== null && str_starts_with($class, 'Botex\\Support\\Log\\')) {
                continue;
            }

            if ($file === null) {
                continue;
            }

            return $this->origin($file, (string) ($frame['function'] ?? ''), $class);
        }

        // Nothing above the logging package: this is the entry script's own
        // global scope, which PHP does not report as a frame. Bootstrap
        // warnings and the webhook's debug line land here.
        $main = get_included_files()[0] ?? null;

        return $main === null ? '?' : strtolower(basename($main, '.php'));
    }

    /**
     * The app frame a throwable was raised from.
     *
     * The throw site's own file is not a trace frame, so the class about to
     * be blamed is the first frame that belongs to this application; vendor
     * frames would name illuminate internals nobody can act on. Falls back
     * to the throwing file's name.
     */
    private function thrownFrom(\Throwable $e): string
    {
        foreach ($e->getTrace() as $frame) {
            $class = $frame['class'] ?? null;
            $file = $frame['file'] ?? null;

            if ($file === null || $class === null) {
                continue;
            }

            if (str_starts_with($class, 'Botex\\') || str_starts_with($class, 'Extensions\\')) {
                return $this->origin($file, (string) $frame['function'], $class);
            }
        }

        return $this->origin($e->getFile(), '', $e::class === \Throwable::class ? null : $e::class);
    }

    /**
     * Resolve and cache a frame's origin.
     *
     * Keyed on file and function together, because a cache keyed on the
     * file alone would report the same origin for every call site in a
     * multi-method class.
     */
    private function origin(string $file, string $function, ?string $class): string
    {
        $key = $file . '|' . $function;

        if (!isset($this->origins[$key])) {
            $this->origins[$key] = $this->describe($file, $function, $class);
        }

        return $this->origins[$key];
    }

    /**
     * Maps a frame to its log origin.
     *
     * The namespace is the whole story: the runtime autoloader puts
     * extension code under Extensions\<Slug>, everything else lives under
     * Botex\, so a class name says which extension raised its hand without
     * any registry lookup.
     */
    private function describe(string $file, string $function, ?string $class): string
    {
        if ($class === null) {
            // Included code at file scope (webhook, console, bootstrap).
            return strtolower(basename($file, '.php'));
        }

        $pos = strrpos($class, '\\');
        $short = $pos === false ? $class : substr($class, $pos + 1);

        if (str_starts_with($class, 'Extensions\\')) {
            $parts = explode('\\', $class);

            return strtolower($parts[1] ?? $short) . ':' . ($function ?: $short);
        }

        if (str_starts_with($class, 'Botex\\')) {
            return 'core:' . $short . ($function ? ':' . $function : '');
        }

        // Third-party code keeps just its short name; the full class would
        // only bloat the line.
        return strtolower($short) . ($function ? ':' . $function : '');
    }
}
