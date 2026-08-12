<?php
// API 路由：/api/v1 前缀，认证路由公开，其余挂 auth:sanctum
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
});

// 临时测试路由：验证权限中间件（Task 4 用户管理路由上线后删除）
// 注意：要求 user.create 而非 user.list——operator 仅持有 list 权限，恰好被 403 拦截（与 RbacTest 拒绝用例对应）
Route::middleware(['auth:sanctum', 'permission:user.create'])->get('/v1/test-permission', fn () => response()->json(['code' => 0]));
// 第二个测试路由：要求 user.list，供 operator 授权放行用例覆盖非 admin 分支（Task 4 一并清理）
Route::middleware(['auth:sanctum', 'permission:user.list'])->get('/v1/test-permission-list', fn () => response()->json(['code' => 0]));
