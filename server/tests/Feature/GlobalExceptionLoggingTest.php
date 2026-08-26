<?php

// 全局异常日志测试：系统异常必记 error（含 URL/方法/堆栈），业务异常族不刷 error（D-14e 回归）
// 用测试内临时路由构造异常路径（不依赖业务数据）；Log::spy 拦截默认日志通道断言调用

namespace Tests\Feature;

use App\Exceptions\ProductionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class GlobalExceptionLoggingTest extends TestCase
{
    public function test_system_exception_logs_error_with_chinese_message_and_context(): void
    {
        // 正常路径：非业务异常（RuntimeException 等）冒泡到全局处理 → 记 error 级中文日志，
        // 携带异常类名/消息/堆栈与请求 URL+方法（不记录请求参数——请求体可能含密码，AGENTS.md §7.2/§8.5）
        Route::get('/api/v1/_test_system_boom', function (): never {
            throw new RuntimeException('模拟系统异常');
        });
        Log::spy();

        $res = $this->getJson('/api/v1/_test_system_boom');

        // 未被任何 render 回调处理的系统异常按框架默认渲染 500
        $res->assertStatus(500);
        Log::shouldHaveReceived('error')->once()->withArgs(
            function (string $message, array $context = []): bool {
                return str_contains($message, '系统异常')
                    && ($context['exception_class'] ?? '') === RuntimeException::class
                    && ($context['message'] ?? '') === '模拟系统异常'
                    && str_contains($context['url'] ?? '', '/api/v1/_test_system_boom')
                    && ($context['method'] ?? '') === 'GET'
                    // 堆栈必须随日志落盘（含堆栈，AGENTS.md §7.2）
                    && str_contains($context['trace'] ?? '', 'GlobalExceptionLoggingTest');
            }
        );
    }

    public function test_business_exception_family_skips_error_log(): void
    {
        // 边界路径：业务异常族（*Exception）属预期业务失败（控制器捕获转统一响应；此处模拟漏网冒泡），
        // 即便到达全局处理也不得刷 error——防止业务噪音淹没真实系统异常告警
        Route::get('/api/v1/_test_biz_boom', function (): never {
            throw new ProductionException('工单当前状态不可入库', 1525);
        });
        Log::spy();

        $this->getJson('/api/v1/_test_biz_boom');

        Log::shouldNotHaveReceived('error');
    }
}
