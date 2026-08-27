<?php

// 领域业务异常全局渲染测试（D-13 回归）：五个业务异常族上抛后由全局异常处理器统一转
// {code, message, data} 信封（HTTP 200，业务码取异常构造第二参数）；
// 控制器已删除自行 catch，真实端点的业务码断言散布在各业务测试，此处用临时路由直证渲染器本体

namespace Tests\Feature;

use App\Exceptions\InventoryException;
use App\Exceptions\ProductionException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GlobalBusinessExceptionRenderTest extends TestCase
{
    public function test_coded_domain_exception_renders_unified_envelope(): void
    {
        // 正常路径：带业务码的领域异常（模拟控制器删除 catch 后的冒泡路径）→
        // HTTP 200 + code=异常自带业务码 + message=异常消息 + data=null，与原控制器 fail() 字节级等价
        Route::get('/api/v1/_test_biz_render', function (): never {
            throw new ProductionException('工单当前状态不可入库', 1525);
        });

        $res = $this->getJson('/api/v1/_test_biz_render');

        $res->assertStatus(200)->assertExactJson([
            'code' => 1525,
            'message' => '工单当前状态不可入库',
            'data' => null,
        ]);
    }

    public function test_codeless_domain_exception_falls_back_to_422_envelope(): void
    {
        // 边界路径：异常未携带业务码（getCode()=0）时兜底 422——防止 code=0 被前端误判为成功；
        // 现网唯一无码 throw（库存引擎库存不足）被控制器保留的语境化 catch 承接，此用例固化兜底契约
        Route::get('/api/v1/_test_biz_render_no_code', function (): never {
            throw new InventoryException('库存不足：商品 1 当前余额 0，出库 10');
        });

        $res = $this->getJson('/api/v1/_test_biz_render_no_code');

        $res->assertStatus(200)->assertExactJson([
            'code' => 422,
            'message' => '库存不足：商品 1 当前余额 0，出库 10',
            'data' => null,
        ]);
    }
}
