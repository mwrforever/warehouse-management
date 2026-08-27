<?php

// 工艺路线业务异常：构造第二参为 17xx 业务码（DAG 校验/引用保护族）；
// 实现 BusinessExceptionInterface，上抛后由全局异常处理器统一渲染为 {code, message, data} 信封（D-13）

namespace App\Exceptions;

use App\Exceptions\Contracts\BusinessExceptionInterface;

class RoutingException extends \RuntimeException implements BusinessExceptionInterface {}
