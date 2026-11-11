<?php

namespace App\Services\TrashNothing;

use RuntimeException;

/** A split that SharedAccountSplitter will not attempt; the message says why. */
class SplitRefused extends RuntimeException
{
}
