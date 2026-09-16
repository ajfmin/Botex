<?php

namespace Botex\Bot\Callback\Admin;

use Botex\Bot\Admin\Section\TopUp;
use Botex\Bot\Callback\CallbackInterface;
use Botex\Bot\Callback\MatchesCallback;
use Botex\Bot\Feeder;
use Botex\Bot\Middleware\IsAdmin;
use Botex\Bot\TopUp\MethodState;
use Botex\Bot\TopUp\PaymentMethods;
use Botex\Support\Log\Logger;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;

/**
 * Switches one payment method on or off.
 *
 * Management, so it is an inline button next to the method it acts on,
 * and the screen is re-rendered in place afterwards -- an admin flipping
 * three routes should end up with one message, not four.
 *
 * The key travels in the callback data, which is user-supplied, so it is
 * checked against the allowlist before anything is written: a made-up
 * key must not be able to create a switch for a method that does not
 * exist. Gated by IsAdmin like every other way into the panel.
 */
class ToggleMethod implements CallbackInterface, MatchesCallback
{
    public const PREFIX = 'admin:topup:m:';

    public function __construct(
        private Bot $bot,
        private PaymentMethods $methods,
        private MethodState $state,
        private Feeder $feeder,
        private Logger $log
    ) {
    }

    public static function callback(): string
    {
        return self::PREFIX;
    }

    public static function middleware(): array
    {
        return [
            IsAdmin::class,
        ];
    }

    public static function to(string $key): string
    {
        return self::PREFIX . $key;
    }

    public static function matches(string $data): bool
    {
        return self::key($data) !== null;
    }

    /**
     * The method key this data names, validated for shape.
     *
     * Checked here rather than in handle(): the allowlist lookup comes
     * after, but anything that could not be a key never needs to reach
     * it. Telegram caps callback data at 64 bytes, so a key long enough
     * to overflow that was never sent by a button of ours.
     */
    private static function key(string $data): ?string
    {
        if (!str_starts_with($data, self::PREFIX)) {
            return null;
        }

        $key = substr($data, strlen(self::PREFIX));

        return preg_match('/^[A-Za-z0-9._-]{1,48}$/', $key) === 1 ? $key : null;
    }

    public function handle(Update $update): void
    {
        $callbackId = $update->callbackId();
        $key = self::key((string) $update->callbackData());

        if ($key === null || !$this->methods->has($key)) {
            if ($callbackId !== null) {
                $this->bot->answerCallback($callbackId, 'That method is no longer installed.', true);
            }

            return;
        }

        $enabled = $this->state->toggle($key);
        $method = $this->methods->make($key);
        $title = $method === null ? $key : $method::title();

        // Re-render rather than send: the admin is looking at the list
        // they just changed, and it should show the change.
        //
        // Guarded, because the switch has already flipped by this point
        // and a failure here is silent on the admin's phone: the screen
        // simply does not change, which reads as "the button did
        // nothing", and the natural response -- press it again -- turns
        // the method straight back off. That is how a working switch
        // became a bug report. If the screen cannot be redrawn, say so
        // in a popup the admin has to dismiss, naming the state it
        // actually landed in.
        try {
            $this->feeder->make(TopUp::class)->methods(
                $update,
                ($enabled ? '🟢 ' : '🔴 ') . $this->escape($title) . ($enabled ? ' switched on.' : ' switched off.')
            );
        } catch (\Throwable $e) {
            $this->log->exception($e, 'Payment method screen could not be redrawn', context: ['method' => $key]);

            if ($callbackId !== null) {
                $this->bot->answerCallback(
                    $callbackId,
                    $title . ($enabled ? ' is now ON.' : ' is now OFF.')
                        . ' The screen could not be redrawn -- do not press again, it would switch back.',
                    true
                );
            }

            return;
        }

        if ($callbackId !== null) {
            $this->bot->answerCallback(
                $callbackId,
                $title . ($enabled ? ' is now on.' : ' is now off.')
            );
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
