<?php

namespace Botex\Remote;

/**
 * Anything that went wrong talking to the archive: unreachable, refused,
 * malformed answer, failed verification.
 */
class RemoteException extends \RuntimeException
{
}
