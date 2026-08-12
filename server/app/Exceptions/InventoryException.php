<?php

// 库存业务异常：出库超卖/审核并发冲突等，由调用方捕获后转业务码（第二参数=业务码，默认 0）

namespace App\Exceptions;

use RuntimeException;

class InventoryException extends RuntimeException {}
