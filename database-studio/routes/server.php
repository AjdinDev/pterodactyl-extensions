<?php

use DatabaseStudio\Http\Controllers\StudioController;
use Illuminate\Support\Facades\Route;

Route::get('/databases', [StudioController::class, 'index']);
Route::prefix('/databases/{databaseId}')->whereNumber('databaseId')->group(function () {
    Route::get('/schema', [StudioController::class, 'schema']);
    Route::get('/rows', [StudioController::class, 'rows']);
    Route::post('/query', [StudioController::class, 'query']);
    Route::post('/import', [StudioController::class, 'import']);
    Route::get('/export', [StudioController::class, 'export']);
    Route::post('/tables', [StudioController::class, 'storeTable']);
    Route::post('/rows', [StudioController::class, 'storeRow']);
    Route::patch('/rows', [StudioController::class, 'updateRow']);
    Route::delete('/rows', [StudioController::class, 'deleteRow']);
});
