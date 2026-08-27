<?php

// 编号规则配置服务：规则更新（type 不可改；date_format 空串归一化）
// 单表原子写，不包 DB::transaction（原子性由单条 update 保证）

namespace App\Services;

use App\Models\DocumentNumberConfig;
use Illuminate\Support\Facades\Log;

class DocumentNumberConfigService
{
    /**
     * 编辑编号规则（原控制器 update 下沉）：type 禁止修改（载荷不含 type，仅白名单字段可写）
     *
     * @param  DocumentNumberConfig  $config  路由绑定的规则模型
     * @param  array  $data  已过 SaveDocumentNumberConfigRequest 格式校验的载荷
     */
    public function update(DocumentNumberConfig $config, array $data): void
    {
        // date_format 允许空字符串（无日期段，如商品编码全局自增）：ConvertEmptyStringsToNull
        // 中间件会把 '' 转 null，写入前归一化回 ''（列 NOT NULL）
        $data['date_format'] = $data['date_format'] ?? '';
        $config->update($data);

        Log::info('编号规则更新成功', ['type' => $config->type, 'prefix' => $config->prefix, 'operator' => auth()->id()]);
    }
}
