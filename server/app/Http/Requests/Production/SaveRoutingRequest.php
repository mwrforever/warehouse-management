<?php

// 工艺路线保存（新建/更新共用）表单校验：单头 + DAG 节点/材料/边格式校验（链接结构校验归 Service）

namespace App\Http\Requests\Production;

use Illuminate\Foundation\Http\FormRequest;

class SaveRoutingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:production.routing.create / production.routing.update 控制，
        // 此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）
     *
     * 与原控制器内联 validatePayload 的 $request->validate 段逐条等价迁移；
     * 「边端点必须存在于节点集中/连线重复/同节点材料重复」三个链接结构校验（422）与
     * 类型归一化随写逻辑下沉 RoutingService（persist 内 normalizePayload），
     * 业务冲突（1701~1710）由 RoutingService 抛 RoutingException 全局渲染。
     */
    public function rules(): array
    {
        return [
            'product_id' => 'required|integer|exists:products,id',
            'version' => 'required|string|max:20',
            'quantity' => 'nullable|numeric|regex:/^\d+(\.\d{1,2})?$/',
            'status' => 'nullable|in:0,1',
            'remark' => 'nullable|string|max:200',
            'nodes' => 'required|array|min:1',
            'nodes.*.node_no' => 'required|string|max:20|distinct',
            'nodes.*.process_id' => 'required|integer|exists:processes,id',
            'nodes.*.name' => 'required|string|max:50',
            'nodes.*.output_product_id' => 'required|integer|exists:products,id',
            'nodes.*.output_qty' => 'nullable|numeric|regex:/^\d+(\.\d{1,2})?$/',
            'nodes.*.is_outsourced' => 'nullable|in:0,1',
            'nodes.*.remark' => 'nullable|string|max:200',
            'nodes.*.materials' => 'nullable|array',
            'nodes.*.materials.*.material_id' => 'required|integer|exists:products,id',
            'nodes.*.materials.*.qty_per_unit' => 'required|numeric|regex:/^\d+(\.\d{1,2})?$/',
            'nodes.*.materials.*.unit_id' => 'required|integer|exists:units,id',
            'edges' => 'nullable|array',
            'edges.*.from_node_no' => 'required|string',
            'edges.*.to_node_no' => 'required|string',
        ];
    }
}
