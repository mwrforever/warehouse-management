<?php

// 统一响应体：全系统所有接口的响应格式模板

namespace App\Support;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * 成功响应：code=0，data 可空
     *
     * @param  int  $options  附加 JSON 编码选项（默认 0 保持既有行为；
     *                        传 JSON_PRESERVE_ZERO_FRACTION 时整值浮点输出 50.0 而非 50）
     */
    protected function ok(mixed $data = null, string $message = '', int $options = 0): JsonResponse
    {
        return response()->json(['code' => 0, 'message' => $message, 'data' => $data], 200, [], $options);
    }

    /** 业务失败响应：非 0 code + 中文 message（HTTP 状态保持 200，由 code 承载业务结果） */
    protected function fail(int $code, string $message, int $httpStatus = 200): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message, 'data' => null], $httpStatus);
    }
}
