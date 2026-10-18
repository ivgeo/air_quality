<?php

use App\Http\Controllers\RankingPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', RankingPageController::class)->name('ranking');
