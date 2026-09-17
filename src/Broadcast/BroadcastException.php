<?php

namespace Botex\Broadcast;

/**
 * A broadcast that was refused before anything was queued.
 *
 * Always something the person asking can act on -- one is already
 * running, there is nobody to send to, there is nothing to say -- so the
 * message is written to be shown to them as it is.
 */
class BroadcastException extends \RuntimeException
{
}
