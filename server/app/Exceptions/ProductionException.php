<?php

// 生产业务异常：BOM 缺失/状态流转冲突/库存不足等（第二参数=业务码，默认 0）；
// 实现 BusinessExceptionInterface，上抛后由全局异常处理器统一渲染为 {code, message, data} 信封（D-13）

namespace App\Exceptions;

use App\Exceptions\Contracts\BusinessExceptionInterface;
use RuntimeException;

class ProductionException extends RuntimeException implements BusinessExceptionInterface {}
