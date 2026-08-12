<?php
// 统一响应体：全系统所有接口的响应格式模板
namespace App\Support;

trait ApiResponse
{
    /** 成功响应：code=0，data 可空 */
    protected function ok(mixed $data = null, string $message = ''): \Illuminate\Http\JsonResponse
    {
        return response()->json(['code' => 0, 'message' => $message, 'data' => $data]);
    }

    /** 业务失败响应：非 0 code + 中文 message（HTTP 状态保持 200，由 code 承载业务结果） */
    protected function fail(int $code, string $message, int $httpStatus = 200): \Illuminate\Http\JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message, 'data' => null], $httpStatus);
    }
}
