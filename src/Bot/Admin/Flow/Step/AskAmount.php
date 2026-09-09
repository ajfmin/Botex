<?php

namespace Botex\Bot\Admin\Flow\Step;

use Botex\Bot\Admin\WalletAction;
use Botex\Bot\Conversation\Answer;
use Botex\Bot\Conversation\Prompt;
use Botex\Bot\Conversation\Session;
use Botex\Bot\Conversation\Step\TextStep;
use Botex\Service\WalletService;
use Botex\Telegram\Update;

/**
 * Reads how much to move, in whole minor units.
 *
 * Digits only, like the console wallet commands: accepting a decimal
 * would mean rounding, and a rounding decision does not belong on the
 * path that moves money.
 */
class AskAmount extends TextStep
{
    /** Comfortably below PHP_INT_MAX, so a long paste cannot overflow. */
    private const MAX_DIGITS = 15;

    public function __construct(
        private WalletService $wallet
    ) {
    }

    public static function name(): string
    {
        return 'amount';
    }

    public function question(Session $session): string
    {
        $lines = [
            'Send the amount to '
                . strtolower(WalletAction::title((string) $session->get('action')))
                . ', in whole minor units.',
            '',
            'Currency: ' . htmlspecialchars($this->wallet->currency(), ENT_QUOTES, 'UTF-8'),
        ];

        $scale = $this->wallet->scale();

        if ($scale > 0) {
            $unit = 10 ** $scale;

            $lines[] = 'Scale is ' . $scale . ', so <code>' . $unit . '</code> means '
                . htmlspecialchars($this->wallet->money($unit)->format(), ENT_QUOTES, 'UTF-8') . '.';
        }

        return implode(PHP_EOL, $lines);
    }

    public function prompt(Session $session): Prompt
    {
        return new Prompt($this->question($session));
    }

    public function validate(Update $update, Session $session): Answer
    {
        $answer = parent::validate($update, $session);

        if (!$answer->valid) {
            return $answer;
        }

        // Separators people type out of habit; dropping them cannot change
        // the value the way a decimal point would.
        $text = str_replace([',', ' ', '_'], '', (string) $answer->value);

        if (!ctype_digit($text)) {
            return Answer::error(
                'An amount is digits only, in minor units. Please try again.'
            );
        }

        if (strlen(ltrim($text, '0')) > self::MAX_DIGITS) {
            return Answer::error('That amount is too large. Please try again.');
        }

        $amount = (int) $text;

        if ($amount <= 0) {
            return Answer::error('The amount has to be more than zero. Please try again.');
        }

        return Answer::ok($amount);
    }
}
