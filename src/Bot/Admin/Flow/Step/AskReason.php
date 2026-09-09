<?php

namespace Botex\Bot\Admin\Flow\Step;

use Botex\Bot\Conversation\Prompt;
use Botex\Bot\Conversation\Session;
use Botex\Bot\Conversation\Step\TextStep;

/**
 * Reads the audit label written to the ledger entry.
 *
 * Required rather than defaulted: the wallet refuses an empty reason, and
 * a manual adjustment is exactly the kind of entry someone will later ask
 * about.
 */
class AskReason extends TextStep
{
    protected int $minLength = 2;

    /** Well inside the reason column, which is a plain string. */
    protected int $maxLength = 120;

    public static function name(): string
    {
        return 'reason';
    }

    public function question(Session $session): string
    {
        return 'Send a short reason for this adjustment. It is stored in the '
            . 'ledger and shown to the user in their wallet history.';
    }

    public function prompt(Session $session): Prompt
    {
        return new Prompt($this->question($session));
    }
}
