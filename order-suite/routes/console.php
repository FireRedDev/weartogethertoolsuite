<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('orders:cleanup')->hourly();
