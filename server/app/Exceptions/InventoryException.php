<?php

// 库存业务异常：出库超卖/审核并发冲突等（第二参数=业务码，默认 0）；
// 实现 BusinessExceptionInterface，上抛后由全局异常处理器统一渲染为 {code, message, data} 信封（D-13）

namespace App\Exceptions;

use App\Exceptions\Contracts\BusinessExceptionInterface;
use RuntimeException;

class InventoryException extends RuntimeException implements BusinessExceptionInterface {}
