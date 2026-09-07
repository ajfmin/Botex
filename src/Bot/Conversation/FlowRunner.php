<?php

namespace Botex\Bot\Conversation;

use Botex\Bot\Feeder;
use Botex\Telegram\Bot;
use Botex\Telegram\Update;

/**
 * Drives a user through a flow: asks, validates, advances, completes.
 *
 * Steps stay declarative; every side effect (sending, saving, clearing)
 * happens here so the rules are in one place.
 */
class FlowRunner
{
    /** Callback data that aborts whatever flow is active. */
    public const CANCEL = 'flow:cancel';

    public function __construct(
        private Store $store,
        private Flows $flows,
        private Feeder $feeder,
        private Bot $bot
    ) {
    }

    /**
     * Begins a flow, replacing any unfinished one, and asks step one.
     *
     * @param array<string, mixed> $data seed values, e.g. an id the
     *                                   flow needs but never asks for
     */
    public function start(string $flowName, Update $update, array $data = []): void
    {
        $telegramId = $this->telegramId($update);

        if ($telegramId === null) {
            return;
        }

        $flow = $this->flows->find($flowName);

        if ($flow === null) {
            throw new \RuntimeException("Flow '{$flowName}' is not registered.");
        }

        $first = $flow::steps()[0] ?? null;

        if ($first === null) {
            throw new \RuntimeException("Flow '{$flowName}' has no steps.");
        }

        $session = new Session(
            telegramId: $telegramId,
            flow: $flowName,
            step: $first::name(),
            data: $data
        );

        $this->store->save($session);
        $this->ask($session, $update);
    }

    public function active(int $telegramId): bool
    {
        return $this->store->find($telegramId) !== null;
    }

    /**
     * Feeds an update into the active flow.
     *
     * @return bool true if a flow consumed it, false to let the router
     *              handle the update normally
     */
    public function handle(Update $update): bool
    {
        $telegramId = $this->telegramId($update);

        if ($telegramId === null) {
            return false;
        }

        if ($update->callbackData() === self::CANCEL) {
            return $this->cancel($update, $telegramId);
        }

        $session = $this->store->find($telegramId);

        if ($session === null) {
            return false;
        }

        $flow = $this->flows->find($session->flow);
        $step = $this->resolveStep($flow, $session->step);

        // The flow or step vanished, most likely because its extension
        // was removed mid-conversation. Drop the session rather than
        // leaving the user stuck answering a question nobody reads.
        if ($flow === null || $step === null) {
            $this->store->clear($telegramId);

            return false;
        }

        $instance = $this->feeder->make($step);
        $answer = $instance->validate($update, $session);
        $this->acknowledge($update);

        if (!$answer->valid) {
            $this->reject($answer->error, $session, $update);

            return true;
        }

        // A repeatable step decides where its answer goes and whether it
        // is finished; everything else stores under the step name once.
        if ($instance instanceof RepeatableStep) {
            $instance->store($session, $answer->value);

            if ($instance->hasMore($session)) {
                $this->store->save($session);
                $this->ask($session, $update);

                return true;
            }
        } else {
            $session->set($session->step, $answer->value);
        }

        $next = $this->nextStep($flow, $session->step);

        if ($next === null) {
            $this->finish($flow, $session, $telegramId);

            return true;
        }

        $session->step = $next::name();
        $this->store->save($session);
        $this->ask($session, $update);

        return true;
    }

    private function cancel(Update $update, int $telegramId): bool
    {
        $existed = $this->store->find($telegramId) !== null;

        $this->store->clear($telegramId);
        $this->acknowledge($update);

        if ($existed) {
            $this->bot->sendMessage('Cancelled.')->to($update->chatId());
        }

        return $existed;
    }

    /**
     * Clears the session before running complete(), so a failure in the
     * extension's own code cannot trap the user in a finished flow.
     */
    private function finish(string $flow, Session $session, int $telegramId): void
    {
        $this->store->clear($telegramId);
        $this->feeder->make($flow)->complete($session);
    }

    private function ask(Session $session, Update $update): void
    {
        $flow = $this->flows->find($session->flow);
        $step = $this->resolveStep($flow, $session->step);

        if ($step === null) {
            return;
        }

        $prompt = $this->feeder->make($step)->prompt($session);

        $this->bot->sendMessage($prompt->text)
            ->to($update->chatId())
            ->parseMode('HTML')
            ->replyMarkup($prompt->keyboard());
    }

    private function reject(string $error, Session $session, Update $update): void
    {
        $message = $error !== '' ? $error : 'That does not look right. Please try again.';

        $this->bot->sendMessage($message)->to($update->chatId());
        $this->ask($session, $update);
    }

    /** Stops the spinner when the answer arrived as a button press. */
    private function acknowledge(Update $update): void
    {
        $callbackId = $update->callbackId();

        if ($callbackId !== null) {
            $this->bot->answerCallback($callbackId);
        }
    }

    /** @return class-string<StepInterface>|null */
    private function resolveStep(?string $flow, string $name): ?string
    {
        if ($flow === null) {
            return null;
        }

        foreach ($flow::steps() as $step) {
            if ($step::name() === $name) {
                return $step;
            }
        }

        return null;
    }

    /** @return class-string<StepInterface>|null */
    private function nextStep(string $flow, string $current): ?string
    {
        $steps = array_values($flow::steps());

        foreach ($steps as $index => $step) {
            if ($step::name() === $current) {
                return $steps[$index + 1] ?? null;
            }
        }

        return null;
    }

    private function telegramId(Update $update): ?int
    {
        $id = $update->fromId();

        return $id === null ? null : (int) $id;
    }
}
