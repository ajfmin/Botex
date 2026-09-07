<?php

namespace Botex\Bot\Action;

/**
 * A button was bound to a target no loaded code contributes.
 *
 * Thrown at bind time only. A press that cannot be resolved is a normal
 * runtime condition, not an exception: the extension may simply have been
 * disabled since, so ActionRunner reports it to the user instead.
 */
class UnknownAction extends \RuntimeException
{
    public function __construct(
        public readonly string $extension,
        public readonly string $action,
        string $message = ''
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : "No runnable named \"{$action}\" is registered for \"{$extension}\"."
        );
    }

    /** A verb that no command declares. */
    public static function command(string $verb): self
    {
        return new self(
            Run::CORE,
            $verb,
            "No command \"/{$verb}\" is registered."
        );
    }
}
