<?php

namespace Botex\Support\Log;

/**
 * Static door to the Logger.
 *
 * Registry boot errors, autowire failures and allowlist rejections happen
 * before, or outside of, anything that can receive the logger through a
 * constructor, and those are exactly the moments worth recording. Same
 * escape hatch as Feeder::share() for Keyboard, and the same discipline:
 * injectable code should inject Logger; this exists for code that cannot.
 */
final class Log
{
    private static ?Logger $logger = null;

    /** Called once by the bootstrap. */
    public static function use(?Logger $logger): void
    {
        self::$logger = $logger;
    }

    public static function logger(): ?Logger
    {
        return self::$logger;
    }

    public static function debug(string $message, array $context = []): void
    {
        self::forward(__FUNCTION__, $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::forward(__FUNCTION__, $message, $context);
    }

    public static function notice(string $message, array $context = []): void
    {
        self::forward(__FUNCTION__, $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::forward(__FUNCTION__, $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::forward(__FUNCTION__, $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::forward(__FUNCTION__, $message, $context);
    }

    /** @see Logger::exception() */
    public static function exception(
        \Throwable $e,
        string $message = '',
        Level $level = Level::Error,
        array $context = []
    ): void {
        if (self::$logger !== null) {
            self::$logger->exception($e, $message, $level, $context);

            return;
        }

        error_log($message !== '' ? $message . ': ' . $e->getMessage() : $e->getMessage());
    }

    /** @see Logger::seen() */
    public static function seen(\Throwable $e): bool
    {
        return Logger::seen($e);
    }

    /**
     * Hands the call to the logger, or to error_log when there is none.
     *
     * Before the bootstrap runs, or if constructing the logger itself
     * failed, silence would be the worst outcome, so the line still goes
     * somewhere.
     */
    private static function forward(string $method, string $message, array $context): void
    {
        if (self::$logger !== null) {
            (self::$logger)->$method($message, $context);

            return;
        }

        error_log(strtoupper($method) . ': ' . $message
            . ($context ? ' ' . json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR) : ''));
    }
}
