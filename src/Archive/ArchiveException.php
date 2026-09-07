<?php

namespace Botex\Archive;

/**
 * Anything wrong with an archive: malformed zip, refused path, failed
 * checksum, unreadable manifest.
 *
 * One class rather than a hierarchy because every caller treats them the
 * same way -- abandon this package, tell the operator why -- and the
 * message is what carries the detail.
 */
class ArchiveException extends \RuntimeException
{
}
