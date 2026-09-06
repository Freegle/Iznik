<?php

// Test fixture for ScheduleProfileTest: what a deployment's schedule overlay
// looks like. It is loaded by routes/console.php when freegle.schedule.overlay
// points here.

use Illuminate\Support\Facades\Schedule;

Schedule::command('list')
    ->hourly()
    ->description('deployment overlay marker');
