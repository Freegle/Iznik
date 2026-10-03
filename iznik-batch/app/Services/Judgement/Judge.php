<?php

namespace App\Services\Judgement;

interface Judge
{
    public function judge(Subject $subject): Verdict;
}
