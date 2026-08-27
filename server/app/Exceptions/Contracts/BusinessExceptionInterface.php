<?php

// 领域业务异常标记接口：五个业务异常族（采购/销售/生产/库存/工艺路线）统一实现本接口，
// 异常上抛后由全局异常处理器（bootstrap/app.php）统一渲染为 {code, message, data} 信封，
// 控制器不再自行 catch 转响应（AGENTS.md §4.4.4，D-13）。
// 标记接口无方法契约：业务码沿用 RuntimeException 构造第二参数，经 getCode() 读取。

namespace App\Exceptions\Contracts;

interface BusinessExceptionInterface {}
