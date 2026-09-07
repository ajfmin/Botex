<?php

namespace Botex\Remote;

/**
 * The archive does not have this package or version.
 *
 * Separate from RemoteException because it is a normal answer, not a
 * failure: "is there a newer version" legitimately gets a no, and a caller
 * checking many extensions should not treat that as the archive being
 * broken.
 */
class NotFound extends RemoteException
{
}
