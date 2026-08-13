<?php

// 生产业务异常：BOM 缺失/状态流转冲突/库存不足等，由调用方捕获后转业务码（第二参数=业务码，默认 0）

namespace App\Exceptions;

use RuntimeException;

class ProductionException extends RuntimeException {}
