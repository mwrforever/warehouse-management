<?php
// API 路由：/api/v1 前缀，认证路由公开，用户管理挂 auth:sanctum + 权限中间件
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth:sanctum');

    // 用户管理：全部要求认证 + 对应权限（user.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:user.list')->get('/users', [UserController::class, 'index']);
        Route::middleware('permission:user.create')->post('/users', [UserController::class, 'store']);
        Route::middleware('permission:user.update')->put('/users/{user}', [UserController::class, 'update']);
        Route::middleware('permission:user.update')->put('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::middleware('permission:user.delete')->delete('/users/{user}', [UserController::class, 'destroy']);
    });
});
