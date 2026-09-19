<?php

use Illuminate\Support\Facades\Route;

Route::get('/realtime/ping', function () {
    return response()->json(['pong' => true]);
});
