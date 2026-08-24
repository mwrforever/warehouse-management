<?php

use App\Exceptions\InventoryException;
use App\Exceptions\ProductionException;
use App\Exceptions\PurchaseException;
use App\Exceptions\RoutingException;
use App\Exceptions\SalesException;
use App\Http\Middleware\EnsurePermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 权限中间件别名：permission:user.list
        $middleware->alias(['permission' => EnsurePermission::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 系统异常统一记 error 级中文日志（AGENTS.md §7.2：系统异常记 error 含堆栈）：
        // 业务异常族（*Exception）属预期业务失败（控制器捕获转统一响应），不刷 error 防噪音；
        // 只记录 URL+方法，不记录请求参数（请求体可能含密码等敏感信息，AGENTS.md §8.5）
        // 本文件处于全局命名空间，Throwable 直接解析为全局类（禁止 use Throwable——非复合名 use 触发 PHP 警告）
        $exceptions->report(function (Throwable $e): void {
            if ($e instanceof PurchaseException || $e instanceof SalesException || $e instanceof ProductionException
                || $e instanceof InventoryException || $e instanceof RoutingException) {
                return;
            }
            $request = request();
            Log::error('系统异常：未捕获异常进入全局处理', [
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
                // console 上下文无请求实例，null 安全兜底
                'url' => $request?->fullUrl(),
                'method' => $request?->getMethod(),
                'trace' => $e->getTraceAsString(),
            ]);
        })->stop(); // 已自行记录（含上下文与堆栈），停止框架默认英文报告，避免同一异常重复落盘
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
        // 资源不存在统一返回信封 404（替代 Laravel 默认 {"message":"Record not found."} 体，保持前后端约定）。
        // 注：ModelNotFoundException 会在 mapException 阶段被转换为 NotFoundHttpException，
        // 故需渲染后者才能同时覆盖隐式路由绑定失败与未匹配路由两种 404。
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['code' => 404, 'message' => '资源不存在', 'data' => null], 404);
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
