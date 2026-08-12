<?php
// API 路由：/api/v1 前缀，认证路由公开，用户管理挂 auth:sanctum + 权限中间件
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DictionaryController;
use App\Http\Controllers\Api\RoleController;
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

    // 角色与权限：全部要求认证 + 对应权限（role.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:role.list')->get('/roles', [RoleController::class, 'index']);
        Route::middleware('permission:role.list')->get('/permissions', [RoleController::class, 'permissions']);
        Route::middleware('permission:role.create')->post('/roles', [RoleController::class, 'store']);
        Route::middleware('permission:role.update')->put('/roles/{role}', [RoleController::class, 'update']);
        Route::middleware('permission:role.delete')->delete('/roles/{role}', [RoleController::class, 'destroy']);
    });

    // 数据字典：按编码取值登录即可访问（供其他模块下拉）；CRUD 要求认证 + 对应权限（dictionary.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        // 注意：code/{code} 必须先于 {dictionary} 注册，避免 code 被解析为字典 ID
        Route::get('/dictionaries/code/{code}', [DictionaryController::class, 'byCode']);
        Route::middleware('permission:dictionary.list')->get('/dictionaries', [DictionaryController::class, 'index']);
        Route::middleware('permission:dictionary.list')->get('/dictionaries/{dictionary}/items', [DictionaryController::class, 'items']);
        Route::middleware('permission:dictionary.create')->post('/dictionaries', [DictionaryController::class, 'store']);
        Route::middleware('permission:dictionary.create')->post('/dictionaries/{dictionary}/items', [DictionaryController::class, 'storeItem']);
        Route::middleware('permission:dictionary.update')->put('/dictionaries/{dictionary}', [DictionaryController::class, 'update']);
        Route::middleware('permission:dictionary.update')->put('/dictionaries/items/{item}', [DictionaryController::class, 'updateItem']);
        Route::middleware('permission:dictionary.delete')->delete('/dictionaries/{dictionary}', [DictionaryController::class, 'destroy']);
        Route::middleware('permission:dictionary.delete')->delete('/dictionaries/items/{item}', [DictionaryController::class, 'destroyItem']);
    });
});
