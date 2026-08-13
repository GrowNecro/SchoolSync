<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientApiController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

Route::get('/download', [ClientApiController::class, 'download'])->middleware('throttle:120,1')->name('download');
Route::get('/installer', [ClientApiController::class, 'installer'])->middleware('throttle:30,1')->name('installer');

Route::middleware('auth')->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/settings', [DashboardController::class, 'settingsPage'])->name('settings');
    Route::get('/files', [DashboardController::class, 'filesPage'])->name('files');
    Route::get('/client-files', [DashboardController::class, 'clientFilesPage'])->name('client-files');
    Route::get('/client-files/{clientSyncedFile}/download', [DashboardController::class, 'downloadClientFile'])->name('client-files.download');
    Route::get('/connection', [DashboardController::class, 'connectionPage'])->name('connection');
    Route::get('/security', [DashboardController::class, 'securityPage'])->name('security');
    Route::get('/status/computers', [DashboardController::class, 'computerStatus'])->name('status.computers');
    Route::post('/actions/open-url', [DashboardController::class, 'openNow'])->middleware('throttle:30,1')->name('actions.open-url');
    Route::post('/actions/open-app', [DashboardController::class, 'openAppNow'])->middleware('throttle:30,1')->name('actions.open-app');
    Route::post('/actions/shutdown', [DashboardController::class, 'shutdownNow'])->middleware('throttle:10,1')->name('actions.shutdown');
    Route::put('/settings', [DashboardController::class, 'update'])->name('settings.update');
    Route::post('/projects', [DashboardController::class, 'uploadProject'])->name('projects.store');
    Route::delete('/projects/{project}', [DashboardController::class, 'deleteProject'])->name('projects.destroy');
    Route::put('/password', [DashboardController::class, 'updatePassword'])->name('password.update');
});

Route::prefix('client')->middleware('throttle:240,1')->group(function (): void {
    Route::get('/config', [ClientApiController::class, 'config'])->name('client.config');
    Route::get('/files', [ClientApiController::class, 'files'])->name('client.files');
    Route::get('/commands', [ClientApiController::class, 'commands'])->name('client.commands');
});
Route::post('/client/heartbeat', [ClientApiController::class, 'heartbeat'])
    ->middleware('throttle:6000,1')
    ->name('client.heartbeat');
Route::post('/client/files/upload', [ClientApiController::class, 'uploadFile'])
    ->middleware('throttle:240,1')
    ->name('client.files.upload');
