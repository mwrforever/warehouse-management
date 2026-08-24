<?php

// 工艺路线业务异常：构造第二参为 17xx 业务码（DAG 校验/引用保护族），由控制器捕获转统一响应

namespace App\Exceptions;

class RoutingException extends \RuntimeException {}
