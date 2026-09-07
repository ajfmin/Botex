<?php

namespace Botex\Extension;

/**
 * Entry point of an extension. Lives in the extension folder as
 * Extension.php and is named in extension.json.
 *
 * Everything here is static on purpose: the registry and the console
 * must be able to inspect an extension without booting it, and the
 * console has no Bot instance to inject.
 *
 * Extend AbstractExtension rather than implementing this directly, so
 * later additions to this contract do not break existing extensions.
 */
interface ExtensionInterface
{
    /**
     * Command classes this extension adds to the router.
     *
     * @return array<class-string>
     */
    public static function commands(): array;

    /**
     * Callback classes this extension adds to the router.
     *
     * @return array<class-string>
     */
    public static function callbacks(): array;

    /**
     * Multi-step conversations this extension provides.
     *
     * @return array<class-string<\Botex\Bot\Conversation\FlowInterface>>
     */
    public static function flows(): array;

    /**
     * Panels this extension adds to /admin.
     *
     * @return array<class-string<\Botex\Bot\Admin\AdminSectionInterface>>
     */
    public static function adminSections(): array;

    /**
     * Named actions a Run button can be bound to.
     *
     * These are what makes an extension reachable from a button built
     * elsewhere: the button stores a slug and an action name, and the
     * allowlist maps that pair back to one of these classes.
     *
     * @return array<class-string<\Botex\Bot\Action\RunnableInterface>>
     */
    public static function runnables(): array;

    /**
     * Named jobs this extension can have scheduled.
     *
     * The worker resolves a jobs row through this allowlist, so an
     * extension that is disabled or removed simply stops having its work
     * run, with no scheduler-side knowledge of extensions.
     *
     * @return array<class-string<\Botex\Bot\Job\JobInterface>>
     */
    public static function jobs(): array;

    /**
     * Called once when the extension is installed or enabled.
     * Create tables here. Must be safe to run twice.
     */
    public static function install(): void;

    /**
     * Called when the extension is removed. Drop tables here.
     */
    public static function uninstall(): void;
}
