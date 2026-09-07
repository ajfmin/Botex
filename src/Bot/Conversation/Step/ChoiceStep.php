<?php

namespace Botex\Bot\Conversation\Step;

use Botex\Bot\Conversation\Answer;
use Botex\Bot\Conversation\Prompt;
use Botex\Bot\Conversation\Session;
use Botex\Bot\Conversation\StepInterface;
use Botex\Telegram\Builders\Keyboard\InlineButton;
use Botex\Telegram\Update;

/**
 * Base for steps that offer a fixed set of buttons.
 *
 * The answer is matched against the declared keys, so a stale keyboard
 * from an old message can never inject a value the step did not offer.
 */
abstract class ChoiceStep implements StepInterface
{
    protected int $perRow = 3;

    abstract public function question(Session $session): string;

    /** @return array<string, string> value => button label */
    abstract public function choices(Session $session): array;

    public function prompt(Session $session): Prompt
    {
        $rows = [];
        $row = [];

        foreach ($this->choices($session) as $value => $label) {
            $row[] = InlineButton::callback(
                $label,
                $this->prefix() . ':' . $value
            );

            if (count($row) === $this->perRow) {
                $rows[] = $row;
                $row = [];
            }
        }

        if ($row !== []) {
            $rows[] = $row;
        }

        return new Prompt($this->question($session), $rows);
    }

    public function validate(Update $update, Session $session): Answer
    {
        $data = $update->callbackData();

        if ($data === null) {
            return Answer::error('Please pick one of the buttons.');
        }

        $expected = $this->prefix() . ':';

        if (!str_starts_with($data, $expected)) {
            return Answer::error('Please pick one of the buttons.');
        }

        $value = substr($data, strlen($expected));

        if (!array_key_exists($value, $this->choices($session))) {
            return Answer::error('That option is no longer available. Please pick another.');
        }

        return Answer::ok($value);
    }

    /** Namespaces this step's callback data so choices cannot collide. */
    protected function prefix(): string
    {
        return 'step:' . static::name();
    }
}
