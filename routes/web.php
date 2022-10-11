<?php

use Illuminate\Support\Facades\Route;
use \App\Http\Controllers as Controllers;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

/* main page */
Route::get('/', [Controllers\MainPageController::class, 'index'])->name('index');
/* На данный роут отправляем запрос */
Route::post('/calc', [Controllers\MainPageController::class, 'calc'])->name('calc');
/* Страница для удобства дебага */
Route::get('/calc', [Controllers\MainPageController::class, 'calc'])->name('calc2');

