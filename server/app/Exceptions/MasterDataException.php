<?php

// 基础资料业务异常：主数据冲突（编码唯一、引用保护、分类层级、安全库存区间等，第二参数=业务码 10xx/11xx）；
// 实现 BusinessExceptionInterface，上抛后由全局异常处理器统一渲染为 {code, message, data} 信封（D-13）

namespace App\Exceptions;

use App\Exceptions\Contracts\BusinessExceptionInterface;
use RuntimeException;

class MasterDataException extends RuntimeException implements BusinessExceptionInterface {}
