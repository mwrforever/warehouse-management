<?php

// BOM 保存（新建/更新共用）表单校验：商品/明细格式校验（商品类型/明细物料类型/重复物料/启用唯一归 Service）

namespace App\Http\Requests\Production;

use Illuminate\Foundation\Http\FormRequest;

class SaveBomRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:production.bom.create / production.bom.update 控制，
        // 此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）
     *
     * 与原控制器内联 validateBom 的 $request->validate 段逐条等价迁移；
     * 商品类型 1118 / 明细物料类型 1119 / 重复物料 1123 / 启用唯一 1120 /
     * 删除引用保护 1121 业务校验下沉 BomService 抛 ProductionException 全局渲染。
     */
    public function rules(): array
    {
        return [
            'product_id' => 'required|exists:products,id',
            'version' => 'required|string|max:20',
            'quantity' => 'nullable|numeric|min:0.01',
            'remark' => 'nullable|string',
            'status' => 'nullable|in:0,1',
            'items' => 'required|array|min:1',
            'items.*.material_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit_id' => 'required|exists:units,id',
        ];
    }
}
