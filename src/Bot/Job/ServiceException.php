<?php

namespace Botex\Bot\Job;

/**
 * A service operation that was refused before anything changed.
 *
 * Always something the operator can act on -- systemd is not here, the
 * unit directory is not writable, systemctl said no and explained why --
 * so the message carries systemd's own words where there are any and is
 * printed as written.
 */
class ServiceException extends \RuntimeException
{
}
