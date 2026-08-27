<?php

// 系统域业务异常：鉴权/用户/角色管理业务冲突（凭证错误 1001、提权保护 1003、
// 角色被引用 1004、账号禁用 1006、唯一管理员保护 1007，第二参数=业务码）；
// 实现 BusinessExceptionInterface，上抛后由全局异常处理器统一渲染为 {code, message, data} 信封（D-13）

namespace App\Exceptions;

use App\Exceptions\Contracts\BusinessExceptionInterface;
use RuntimeException;

class SystemException extends RuntimeException implements BusinessExceptionInterface {}
