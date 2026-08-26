<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Sanctum SPA 握手端点（R4-3）：前端在每个会话开始时先 GET 本端点，换取 XSRF-TOKEN 与 laravel_session cookie；
// 此后携带 cookie 的 API 请求才会命中会话鉴权链路（EnsureFrontendRequestsAreStateful + StartSession + CSRF 校验）。
// 挂在 web 中间件组下（自带 StartSession/CSRF 中间件），与 Sanctum SPA 官方约定一致
Route::get('/sanctum/csrf-cookie', function () {
    return response()->noContent();
});
