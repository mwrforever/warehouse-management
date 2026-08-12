<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 权限中间件别名：permission:user.list
        $middleware->alias(['permission' => \App\Http\Middleware\EnsurePermission::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 未认证与无权限统一返回 JSON（前后端分离约定）
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['code' => 401, 'message' => '未认证或登录已过期', 'data' => null], 401);
            }
        });
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['code' => 403, 'message' => '无权限操作', 'data' => null], 403);
            }
        });
        // 表单校验失败统一返回 JSON：username 唯一性冲突（重复用户名）属业务错误归 code=1002，
        // 其余校验失败（缺字段/弱密码/无效角色等）code 与 HTTP 状态一致为 422，避免机器端误判
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                // 仅 Unique 规则命中才报 1002，且 message 固定为「用户名已存在」与 code 语义一致
                $duplicateUsername = isset($e->validator->failed()['username']['Unique']);
                return response()->json([
                    'code' => $duplicateUsername ? 1002 : 422,
                    'message' => $duplicateUsername ? '用户名已存在' : $e->validator->errors()->first(),
                    'data' => null,
                ], 422);
            }
        });
    })->create();
