<?php

namespace Botex\Update;

/**
 * An update could not be performed safely.
 *
 * Thrown for a refused conflict, a failed requirement, an unwritable path
 * or a failed swap. Every one of them means nothing was installed -- an
 * update either completes or leaves the install as it was, so seeing this
 * is always safe to retry after fixing what it names.
 */
class UpdateException extends \RuntimeException
{
}
