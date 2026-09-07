<?php

namespace Botex\Bot;

use Botex\Bot\Middleware\MiddlewareInterface;
use Botex\Support\Log\Level;
use Botex\Support\Log\Logger;
use Botex\Telegram\Update;

/**
 * Resolves a handler class, runs its middleware chain, and only
 * calls handle() if every middleware allowed the request through.
 */
class Dispatcher
{
    public function __construct(
        private Feeder $feeder,
        private Logger $log
    ) {
    }

    public function dispatch(string $class, Update $update): void
    {
        if (!$this->guard($class, $update)) {
            return;
        }

        $handler = $this->feeder->make($class);

        try {
            $handler->handle($update);
        } catch (\Throwable $e) {
            // Recorded here with the handler's own context, then rethrown so
            // the webhook catch still answers the request with a non-200.
            $this->log->exception($e, 'Handler failed', Level::Error, [
                'handler' => $class,
            ]);

            throw $e;
        }
    }

    /**
     * Runs a class's middleware chain and reports whether it passed.
     *
     * Public because handlers that are not dispatched directly still need
     * the same check: ActionRunner calls this for a runnable it invokes
     * itself, rather than repeating the chain logic.
     */
    public function guard(string $class, Update $update): bool
    {
        foreach ($class::middleware() as $middleware) {
            $instance = $this->feeder->make($middleware);

            if (!$instance instanceof MiddlewareInterface) {
                throw new \RuntimeException(
                    "{$middleware} must implement MiddlewareInterface."
                );
            }

            if (!$instance->handle($update)) {
                return false;
            }
        }

        return true;
    }
}
