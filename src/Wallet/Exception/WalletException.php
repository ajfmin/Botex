<?php

namespace Botex\Wallet\Exception;

/**
 * Base for every wallet domain error, so a caller can catch the whole
 * family without catching unrelated runtime failures.
 */
class WalletException extends \RuntimeException
{
}
