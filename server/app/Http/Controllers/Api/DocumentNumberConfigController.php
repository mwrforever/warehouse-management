<?php

// 编号规则配置控制器：列表/编辑/预览（Spec 2 配置驱动；类型由种子固定，无新建/删除）

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentNumberConfig;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentNumberConfigController extends Controller
{
    use ApiResponse;

    /** 类型 → 中文标签（与 DocumentSequence 常量注释对应，前端表格展示） */
    private const TYPE_LABELS = [
        'check' => '盘点单', 'bom' => 'BOM', 'po' => '采购订单', 'pi' => '采购入库单',
        'so' => '销售订单', 'sout' => '销售出库单', 'mo' => '生产工单', 'pl' => '生产领料单',
        'rl' => '生产退料单', 'os' => '委外加工单', 'osr' => '委外回收单', 'osrt' => '委外退料', 'fi' => '成品入库单',
        'rtg' => '工艺路线', 'prd' => '商品编码',
    ];

    /** 分页列表：按类型排序，附类型中文标签 */
    public function index(Request $request)
    {
        $rows = DocumentNumberConfig::orderBy('id')->paginate(max(1, min(100, (int) $request->input('per_page', 20))));

        return $this->ok([
            'items' => $rows->map(fn ($c) => $this->payload($c)),
            'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(),
        ]);
    }

    /** 编辑规则：前缀大写字母 2~4 位、日期格式枚举（含空=无日期段）、序列长度 1~10；type 禁止修改 */
    public function update(Request $request, DocumentNumberConfig $config)
    {
        $data = $request->validate([
            'prefix' => 'required|string|max:10|regex:/^[A-Z]{2,4}$/',
            // date_format 允许空字符串（无日期段，如商品编码全局自增）：present 保证键存在但空串合法（required 会误拒）
            'date_format' => ['present', Rule::in(['', 'Ymd', 'YmdHi', 'YmdHis'])],
            'seq_length' => 'required|integer|between:1,10',
            'enabled' => 'required|boolean',
            'remark' => 'nullable|string|max:255',
        ]);
        // date_format 允许空字符串（无日期段，如商品编码全局自增）：present 保证键存在但空串合法（required 会误拒）；
        // 注意 ConvertEmptyStringsToNull 中间件会把 '' 转 null，写入前归一化回 ''（列 NOT NULL）
        $data['date_format'] = $request->input('date_format') === null ? '' : $data['date_format'];
        $config->update($data);

        return $this->ok($this->payload($config->refresh()));
    }

    /** 规则预览：按提交的临时规则生成一条示例单号（不落库、不占号），供编辑弹窗核对 */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'prefix' => 'required|string|max:10|regex:/^[A-Z]{2,4}$/',
            // 同上：date_format 空串是合法规则（无日期段）；ConvertEmptyStringsToNull 会转 null，归一化回 '' 复用 update 方式
            'date_format' => ['present', Rule::in(['', 'Ymd', 'YmdHi', 'YmdHis'])],
            'seq_length' => 'required|integer|between:1,10',
        ]);
        $dateFormat = $request->input('date_format') === null ? '' : $data['date_format'];
        $dateKey = $dateFormat === '' ? '' : date($dateFormat);
        $no = $data['prefix'].$dateKey.str_pad('1', $data['seq_length'], '0', STR_PAD_LEFT);

        return $this->ok(['no' => $no]);
    }

    private function payload(DocumentNumberConfig $c): array
    {
        return [
            'id' => $c->id, 'type' => $c->type, 'type_label' => self::TYPE_LABELS[$c->type] ?? $c->type,
            'prefix' => $c->prefix, 'date_format' => $c->date_format, 'seq_length' => $c->seq_length,
            'enabled' => (bool) $c->enabled, 'remark' => $c->remark,
        ];
    }
}
