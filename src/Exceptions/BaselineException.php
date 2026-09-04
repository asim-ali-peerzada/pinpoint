<?php

namespace AsimAli\Pinpoint\Exceptions;

use RuntimeException;

/**
 * @internal Thrown for baseline snapshot failures (missing tag, empty
 * window, corrupt snapshot, overwrite conflicts). Commands catch this
 * alongside InvalidArgumentException (user input errors).
 */
class BaselineException extends RuntimeException {}
