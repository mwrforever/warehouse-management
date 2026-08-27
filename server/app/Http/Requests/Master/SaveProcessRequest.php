<?php

// 工序保存（新建/更新共用）表单校验：名称/分类/排序/描述/状态格式校验（编码由服务自动生成不手填）

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;

class SaveProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 权限由路由中间件 permission:process.create / permission:process.update 控制，
        // 此处不再重复判断（避免与中间件口径漂移）
        return true;
    }

    /**
     * 载荷格式校验规则（422 仅格式层）
     *
     * 编码不再接收手填（由 ProcessService 经 DocumentSequenceService 自动生成 PROC 前缀编码），
     * 故 rules 不含 code；分类为可空字典项外键，传空即清除标签分类。
     *
     * @return array<string, string> 字段名 => 规则串（Laravel 校验语法）
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:50',
            'category_id' => 'nullable|integer|exists:dictionary_items,id',
            'sort' => 'nullable|integer',
            'description' => 'nullable|string',
            'status' => 'nullable|in:0,1',
        ];
    }
}
