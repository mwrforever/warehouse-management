<?php

// 销售业务异常：审核冲突/超卖拦截等，由调用方捕获后转业务码（第二参数=业务码，默认 0）

namespace App\Exceptions;

use RuntimeException;

class SalesException extends RuntimeException {}
