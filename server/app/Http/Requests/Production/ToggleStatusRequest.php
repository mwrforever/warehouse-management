<?php

// 启用/停用切换（BOM/工艺路线共用）表单校验：状态必填 0/1（切换事务归各领域 Service）

namespace App\Http\Requests\Production;

use Illuminate\Foundation\Http\FormRequest;

class ToggleStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:production.bom.update / production.routing.update 控制，
        // 此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /** 切换载荷格式校验（422 仅格式层）；启停的业务含义（启用唯一）由 BomService/RoutingService 各自落实 */
    public function rules(): array
    {
        return [
            'status' => 'required|in:0,1',
        ];
    }
}
