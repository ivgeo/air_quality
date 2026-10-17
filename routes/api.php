<?php

use App\Http\Controllers\Api\V1\RankingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('ranking', RankingController::class)->name('api.v1.ranking');
});
