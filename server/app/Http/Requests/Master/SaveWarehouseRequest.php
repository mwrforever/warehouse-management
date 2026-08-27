<?php

// 仓库保存（新建/更新共用）表单校验：名称/四级地址/详细地址/负责人/状态格式校验
// （编码不再由前端提供，交由 WarehouseService 按号段自动生成，故本表无 code 规则）

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;

class SaveWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:warehouse.create / permission:warehouse.update 控制，
        // 此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）
     *
     * 编码由 WarehouseService 经 DocumentSequenceService 自动生成，载荷不再接收 code；
     * 四级地址为区划名称（文本落库），详细地址沿用 address 字段。
     *
     * @return array<string, string> 字段名 => 规则串（Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:50',
            'address' => 'nullable|string',
            'province' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:50',
            'district' => 'nullable|string|max:50',
            'town' => 'nullable|string|max:50',
            'manager' => 'nullable|string|max:50',
            'status' => 'nullable|in:0,1',
        ];
    }
}
