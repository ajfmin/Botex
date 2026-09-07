<?php

namespace Botex\Extension;

use Botex\Support\Log\Level;
use Botex\Support\Log\Log;

/**
 * Discovers extensions on disk and collects what they contribute.
 *
 * A broken extension is skipped and recorded in errors() rather than
 * taking the whole bot down with it.
 */
class Registry
{
    /** @var array<string, Manifest>|null */
    private ?array $manifests = null;

    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(
        private string $path,
        private State $state
    ) {
        Autoloader::register($this->path);
    }

    /**
     * Every extension on disk, enabled or not, keyed by slug.
     *
     * @return array<string, Manifest>
     */
    public function all(): array
    {
        if ($this->manifests !== null) {
            return $this->manifests;
        }

        $this->manifests = [];

        if (!is_dir($this->path)) {
            return $this->manifests;
        }

        foreach ((array) scandir($this->path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $dir = $this->path . '/' . $entry;

            if (!is_dir($dir)) {
                continue;
            }

            try {
                $this->manifests[$entry] = Manifest::fromPath($dir);
            } catch (\Throwable $e) {
                $this->errors[$entry] = $e->getMessage();
                $this->logSkip($entry, $e);
            }
        }

        ksort($this->manifests);

        return $this->manifests;
    }

    /** @return array<string, Manifest> */
    public function enabled(): array
    {
        return array_filter(
            $this->all(),
            fn(Manifest $m) => $this->state->isEnabled($m->slug)
        );
    }

    public function find(string $slug): ?Manifest
    {
        return $this->all()[$slug] ?? null;
    }

    public function isEnabled(string $slug): bool
    {
        return $this->state->isEnabled($slug);
    }

    /** @return array<string, string> slug => error message */
    public function errors(): array
    {
        $this->all();

        return $this->errors;
    }

    /**
     * Resolves and validates an extension's entry class.
     *
     * @return class-string<ExtensionInterface>
     */
    public function entryClass(Manifest $manifest): string
    {
        $class = $manifest->entry;

        if (!class_exists($class)) {
            throw new \RuntimeException(
                "{$manifest->slug}: entry class {$class} was not found."
            );
        }

        if (!is_subclass_of($class, ExtensionInterface::class)) {
            throw new \RuntimeException(
                "{$manifest->slug}: {$class} must implement ExtensionInterface."
            );
        }

        return $class;
    }

    /**
     * Command classes contributed by every enabled extension.
     *
     * @return array<class-string>
     */
    public function commands(): array
    {
        return $this->collect('commands');
    }

    /**
     * Callback classes contributed by every enabled extension.
     *
     * @return array<class-string>
     */
    public function callbacks(): array
    {
        return $this->collect('callbacks');
    }

    /**
     * Flow classes contributed by every enabled extension.
     *
     * @return array<class-string<\Botex\Bot\Conversation\FlowInterface>>
     */
    public function flows(): array
    {
        return $this->collect('flows');
    }

    /**
     * Admin panel sections contributed by every enabled extension.
     *
     * @return array<class-string<\Botex\Bot\Admin\AdminSectionInterface>>
     */
    public function adminSections(): array
    {
        return $this->collect('adminSections');
    }

    /**
     * Runnable action classes contributed by every enabled extension,
     * keyed by slug.
     *
     * Grouped rather than flattened because a stored action names both an
     * extension and an action, and two extensions are free to use the
     * same action name.
     *
     * @return array<string, array<class-string<\Botex\Bot\Action\RunnableInterface>>>
     */
    public function runnables(): array
    {
        return $this->collectBySlug('runnables');
    }

    /**
     * Job handler classes contributed by every enabled extension, keyed by
     * slug.
     *
     * Grouped for the same reason runnables are: a jobs row names both an
     * extension and a job. It is also what makes a disabled extension's
     * jobs stop running without the worker knowing anything about
     * extensions.
     *
     * @return array<string, array<class-string<\Botex\Bot\Job\JobInterface>>>
     */
    public function jobs(): array
    {
        return $this->collectBySlug('jobs');
    }

    /** @return array<class-string> */
    private function collect(string $method): array
    {
        $collected = [];

        foreach ($this->enabled() as $manifest) {
            try {
                $class = $this->entryClass($manifest);

                // An extension written against an older contract may not
                // have this hook. Treat it as contributing nothing rather
                // than failing the whole extension.
                if (!method_exists($class, $method)) {
                    continue;
                }

                foreach ($class::$method() as $contributed) {
                    $collected[] = $contributed;
                }
            } catch (\Throwable $e) {
                if (!isset($this->errors[$manifest->slug])) {
                    $this->errors[$manifest->slug] = $e->getMessage();
                    $this->logSkip($manifest->slug, $e);
                }
            }
        }

        return $collected;
    }

    /**
     * Same as collect(), but preserves which extension contributed what.
     *
     * @return array<string, array<class-string>>
     */
    private function collectBySlug(string $method): array
    {
        $collected = [];

        foreach ($this->enabled() as $manifest) {
            try {
                $class = $this->entryClass($manifest);

                if (!method_exists($class, $method)) {
                    continue;
                }

                $contributed = (array) $class::$method();

                if ($contributed !== []) {
                    $collected[$manifest->slug] = array_values($contributed);
                }
            } catch (\Throwable $e) {
                if (!isset($this->errors[$manifest->slug])) {
                    $this->errors[$manifest->slug] = $e->getMessage();
                    $this->logSkip($manifest->slug, $e);
                }
            }
        }

        return $collected;
    }

    /**
     * Records a skipped extension, once per slug per process.
     *
     * Through the Log facade rather than an injected logger: the bootstrap
     * builds the Registry before the Logger exists. collect() runs on every
     * dispatch, so without the errors() guard a broken extension would
     * write a line each time. Warning, not error: the bot still serves
     * everything else, and admins see the failure in ext:list, but it is
     * on the file with the stack behind it.
     */
    private function logSkip(string $slug, \Throwable $e): void
    {
        Log::exception($e, "Extension {$slug} skipped", Level::Warning, [
            'extension' => $slug,
        ]);
    }
}
