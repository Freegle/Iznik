<?php

namespace App\Services\Ux;

/**
 * An experiment action the rules say no to: the cap of running experiments, a second experiment
 * on the same flow, a change too small to read in a day, a status the action does not apply to.
 * Not a fault. The background task records the reason on the experiment for the person who asked,
 * and does not retry, because the same rules would refuse it again.
 */
class UxExperimentRefused extends \RuntimeException
{
}
