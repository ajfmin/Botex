<?php

namespace Botex\Bot\TopUp;

use Botex\Telegram\Update;

/**
 * One way for a customer to put money in their wallet.
 *
 * Core owns the *shape* of a top-up -- the list a customer picks from,
 * the on/off switch an admin flips, the ledger every method writes to,
 * and the report read back off it. Core owns none of the *paying*. A
 * card transfer reviewed by a human, a gateway redirect, a crypto
 * address, a voucher code: each of those is an extension, and this is
 * the whole of what core asks of one.
 *
 * That split is deliberate. Payment is the part of a bot most likely to
 * be country-specific, to need credentials core should never hold, and
 * to be replaced without notice -- so it lives where it can be installed
 * and removed, while the accounting it feeds stays put.
 *
 * Implementations are listed in an extension's `paymentMethods()` hook
 * and resolved through [[PaymentMethods]], so a stored row never names a
 * class and a disabled extension simply stops offering its method.
 */
interface PaymentMethodInterface
{
    /**
     * Stable identifier, unique across every installed extension.
     *
     * It is written to every `topups` row and to the on/off state file,
     * so renaming one orphans its history and silently switches the new
     * name on. Treat it as published the moment a customer uses it.
     *
     * MUST match `[A-Za-z0-9._-]+`.
     */
    public static function key(): string;

    /** What the customer sees on the button. */
    public static function title(): string;

    /**
     * One line under the title on the admin's screen.
     *
     * An instance method rather than a static one, so it can report the
     * method's actual condition and not just its purpose -- "no card
     * number set", "test credentials". An admin who has switched a
     * method on and is watching nothing happen is reading this line to
     * find out why, and a method is the only thing that knows.
     */
    public function description(): string;

    /**
     * Whether this method can actually take money right now.
     *
     * Separate from the admin's on/off switch, and answering a different
     * question: the switch is what an operator wants, this is what the
     * method can deliver. A gateway with no API key, a card method with
     * no card number -- switched on and unusable. Core hides it from
     * customers either way, and shows an admin which of the two it is.
     */
    public function isConfigured(): bool;

    /**
     * Begins a top-up.
     *
     * Called when the customer picks this method, with the update that
     * picked it. What happens next is entirely the extension's: ask for
     * a photo, send a payment link, open a flow. Core's involvement ends
     * here and resumes when the extension calls
     * [[TopUpService::credit()]] with a settled amount.
     */
    public function start(Update $update): void;
}
