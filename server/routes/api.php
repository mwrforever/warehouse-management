<?php

// API 路由：/api/v1 前缀，认证路由公开，业务路由挂 auth:sanctum + 权限中间件
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BomController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CheckController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DictionaryController;
use App\Http\Controllers\Api\DocumentNumberConfigController;
use App\Http\Controllers\Api\FinishedInboundController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\OperationReportController;
use App\Http\Controllers\Api\OutsourcingController;
use App\Http\Controllers\Api\PickListController;
use App\Http\Controllers\Api\ProcessController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductionOrderController;
use App\Http\Controllers\Api\PurchaseInboundController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReturnListController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RoutingController;
use App\Http\Controllers\Api\SalesOrderController;
use App\Http\Controllers\Api\SalesOutboundController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth:sanctum');

    // 用户管理：全部要求认证 + 对应权限（user.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:user.list')->get('/users', [UserController::class, 'index']);
        Route::middleware('permission:user.create')->post('/users', [UserController::class, 'store']);
        Route::middleware('permission:user.update')->put('/users/{user}', [UserController::class, 'update']);
        Route::middleware('permission:user.update')
            ->put('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::middleware('permission:user.delete')->delete('/users/{user}', [UserController::class, 'destroy']);
    });

    // 角色与权限：全部要求认证 + 对应权限（role.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:role.list')->get('/roles', [RoleController::class, 'index']);
        Route::middleware('permission:role.list')->get('/permissions', [RoleController::class, 'permissions']);
        Route::middleware('permission:role.create')->post('/roles', [RoleController::class, 'store']);
        Route::middleware('permission:role.update')->put('/roles/{role}', [RoleController::class, 'update']);
        Route::middleware('permission:role.delete')->delete('/roles/{role}', [RoleController::class, 'destroy']);
    });

    // 数据字典：全部要求认证 + 对应权限（dictionary.list/create/update/delete）；
    // 按编码取值与列表同口径要求 dictionary.list（D-11 权限收紧：byCode 为公开字典查询，
    // 管理员配置的角色通常持有；前端 api/dictionary.ts 未调用该接口，无实际影响面）
    Route::middleware('auth:sanctum')->group(function () {
        // 注意：code/{code} 必须先于 {dictionary} 注册，避免 code 被解析为字典 ID
        Route::middleware('permission:dictionary.list')
            ->get('/dictionaries/code/{code}', [DictionaryController::class, 'byCode']);
        Route::middleware('permission:dictionary.list')->get('/dictionaries', [DictionaryController::class, 'index']);
        Route::middleware('permission:dictionary.list')
            ->get('/dictionaries/{dictionary}/items', [DictionaryController::class, 'items']);
        Route::middleware('permission:dictionary.create')
            ->post('/dictionaries', [DictionaryController::class, 'store']);
        Route::middleware('permission:dictionary.create')
            ->post('/dictionaries/{dictionary}/items', [DictionaryController::class, 'storeItem']);
        Route::middleware('permission:dictionary.update')
            ->put('/dictionaries/{dictionary}', [DictionaryController::class, 'update']);
        Route::middleware('permission:dictionary.update')
            ->put('/dictionaries/items/{item}', [DictionaryController::class, 'updateItem']);
        Route::middleware('permission:dictionary.delete')
            ->delete('/dictionaries/{dictionary}', [DictionaryController::class, 'destroy']);
        Route::middleware('permission:dictionary.delete')
            ->delete('/dictionaries/items/{item}', [DictionaryController::class, 'destroyItem']);
    });

    // 编号规则（Spec 2）：列表/预览登录可读（system.setting.list）；编辑需 system.setting.update
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:system.setting.list')
            ->get('/document-number-configs', [DocumentNumberConfigController::class, 'index']);
        Route::middleware('permission:system.setting.list')
            ->post('/document-number-configs/preview', [DocumentNumberConfigController::class, 'preview']);
        Route::middleware('permission:system.setting.update')
            ->put('/document-number-configs/{config}', [DocumentNumberConfigController::class, 'update']);
    });

    // 分类：树形列表 + CRUD（category.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:category.list')->get('/categories', [CategoryController::class, 'index']);
        Route::middleware('permission:category.create')->post('/categories', [CategoryController::class, 'store']);
        Route::middleware('permission:category.update')
            ->put('/categories/{category}', [CategoryController::class, 'update']);
        Route::middleware('permission:category.delete')
            ->delete('/categories/{category}', [CategoryController::class, 'destroy']);
    });

    // 单位：CRUD（unit.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:unit.list')->get('/units', [UnitController::class, 'index']);
        Route::middleware('permission:unit.create')->post('/units', [UnitController::class, 'store']);
        Route::middleware('permission:unit.update')->put('/units/{unit}', [UnitController::class, 'update']);
        Route::middleware('permission:unit.delete')->delete('/units/{unit}', [UnitController::class, 'destroy']);
    });

    // 工序：列表 + CRUD（process.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:process.list')->get('/processes', [ProcessController::class, 'index']);
        Route::middleware('permission:process.create')->post('/processes', [ProcessController::class, 'store']);
        Route::middleware('permission:process.update')
            ->put('/processes/{process}', [ProcessController::class, 'update']);
        Route::middleware('permission:process.delete')
            ->delete('/processes/{process}', [ProcessController::class, 'destroy']);
    });

    // 仓库/库位：CRUD + 库位子资源（warehouse.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:warehouse.list')->get('/warehouses', [WarehouseController::class, 'index']);
        Route::middleware('permission:warehouse.create')->post('/warehouses', [WarehouseController::class, 'store']);
        Route::middleware('permission:warehouse.update')
            ->put('/warehouses/{warehouse}', [WarehouseController::class, 'update']);
        Route::middleware('permission:warehouse.delete')
            ->delete('/warehouses/{warehouse}', [WarehouseController::class, 'destroy']);
        Route::middleware('permission:warehouse.list')
            ->get('/warehouses/{warehouse}/locations', [WarehouseController::class, 'locations']);
        Route::middleware('permission:warehouse.create')
            ->post('/warehouses/{warehouse}/locations', [WarehouseController::class, 'storeLocation']);
        Route::middleware('permission:warehouse.update')
            ->put('/locations/{location}', [WarehouseController::class, 'updateLocation']);
        Route::middleware('permission:warehouse.delete')
            ->delete('/locations/{location}', [WarehouseController::class, 'destroyLocation']);
    });

    // 供应商：CRUD（supplier.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:supplier.list')->get('/suppliers', [SupplierController::class, 'index']);
        Route::middleware('permission:supplier.create')->post('/suppliers', [SupplierController::class, 'store']);
        Route::middleware('permission:supplier.update')
            ->put('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::middleware('permission:supplier.delete')
            ->delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);
    });

    // 客户：CRUD（customer.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:customer.list')->get('/customers', [CustomerController::class, 'index']);
        Route::middleware('permission:customer.create')->post('/customers', [CustomerController::class, 'store']);
        Route::middleware('permission:customer.update')
            ->put('/customers/{customer}', [CustomerController::class, 'update']);
        Route::middleware('permission:customer.delete')
            ->delete('/customers/{customer}', [CustomerController::class, 'destroy']);
    });

    // 商品：CRUD + 扫码查询（product.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:product.list')->get('/products', [ProductController::class, 'index']);
        Route::middleware('permission:product.list')
            ->get('/products/barcode/{barcode}', [ProductController::class, 'byBarcode']);
        Route::middleware('permission:product.create')->post('/products', [ProductController::class, 'store']);
        Route::middleware('permission:product.update')
            ->put('/products/{product}', [ProductController::class, 'update']);
        Route::middleware('permission:product.delete')
            ->delete('/products/{product}', [ProductController::class, 'destroy']);
    });

    // BOM：CRUD + 明细 + 启用切换（bom.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:bom.list')->get('/boms', [BomController::class, 'index']);
        Route::middleware('permission:bom.create')->post('/boms', [BomController::class, 'store']);
        Route::middleware('permission:bom.update')->put('/boms/{bom}', [BomController::class, 'update']);
        Route::middleware('permission:bom.delete')->delete('/boms/{bom}', [BomController::class, 'destroy']);
        Route::middleware('permission:bom.list')->get('/boms/{bom}/items', [BomController::class, 'items']);
        Route::middleware('permission:bom.update')->put('/boms/{bom}/toggle', [BomController::class, 'toggle']);
    });

    // 工艺路线：CRUD + DAG 图 + 启停（routing.*；DAG 校验 17xx 在 Service；graph 静态段先于 {routing} 注册）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:routing.list')->get('/routings', [RoutingController::class, 'index']);
        Route::middleware('permission:routing.create')->post('/routings', [RoutingController::class, 'store']);
        Route::middleware('permission:routing.list')->get('/routings/{routing}/graph', [RoutingController::class, 'graph']);
        Route::middleware('permission:routing.update')->put('/routings/{routing}', [RoutingController::class, 'update']);
        Route::middleware('permission:routing.delete')->delete('/routings/{routing}', [RoutingController::class, 'destroy']);
        Route::middleware('permission:routing.update')->put('/routings/{routing}/toggle', [RoutingController::class, 'toggle']);
    });

    // 库存查询：余额/导出/流水/预警（inventory.list）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:inventory.list')
            ->get('/inventory/balances', [InventoryController::class, 'balances']);
        Route::middleware('permission:inventory.list')
            ->get('/inventory/balances/export', [InventoryController::class, 'exportBalances']);
        Route::middleware('permission:inventory.list')
            ->get('/inventory/movements', [InventoryController::class, 'movements']);
        Route::middleware('permission:inventory.list')
            ->get('/inventory/alerts', [InventoryController::class, 'alerts']);
    });

    // 盘点单：CRUD + 账面预填 + 审核（check.list/create/update/delete；审核复用 check.update）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:check.list')->get('/checks/auto-books', [CheckController::class, 'autoBooks']);
        Route::middleware('permission:check.list')->get('/checks', [CheckController::class, 'index']);
        Route::middleware('permission:check.create')->post('/checks', [CheckController::class, 'store']);
        Route::middleware('permission:check.list')->get('/checks/{check}', [CheckController::class, 'show']);
        Route::middleware('permission:check.update')->put('/checks/{check}', [CheckController::class, 'update']);
        Route::middleware('permission:check.delete')->delete('/checks/{check}', [CheckController::class, 'destroy']);
        Route::middleware('permission:check.update')
            ->post('/checks/{check}/approve', [CheckController::class, 'approve']);
    });

    // 采购订单：CRUD + 审核/关闭 + 可入库列表 + 入库记录（purchase.order.*；审核/关闭复用 update）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:purchase.order.list')->get('/purchase/orders/available', [PurchaseOrderController::class, 'available']);
        Route::middleware('permission:purchase.order.list')->get('/purchase/orders', [PurchaseOrderController::class, 'index']);
        Route::middleware('permission:purchase.order.create')->post('/purchase/orders', [PurchaseOrderController::class, 'store']);
        Route::middleware('permission:purchase.order.list')->get('/purchase/orders/{order}', [PurchaseOrderController::class, 'show']);
        Route::middleware('permission:purchase.order.update')->put('/purchase/orders/{order}', [PurchaseOrderController::class, 'update']);
        Route::middleware('permission:purchase.order.update')->post('/purchase/orders/{order}/approve', [PurchaseOrderController::class, 'approve']);
        Route::middleware('permission:purchase.order.update')->post('/purchase/orders/{order}/close', [PurchaseOrderController::class, 'close']);
        Route::middleware('permission:purchase.order.delete')->delete('/purchase/orders/{order}', [PurchaseOrderController::class, 'destroy']);
        Route::middleware('permission:purchase.order.list')->get('/purchase/orders/{order}/inbounds', [PurchaseOrderController::class, 'inbounds']);
    });

    // 采购入库单：CRUD + from-order 预填 + 审核（purchase.inbound.*；审核复用 update）
    Route::middleware('auth:sanctum')->group(function () {
        // 注意：from-order 必须先于 {inbound} 注册，避免 orderId 被解析为入库单 ID
        Route::middleware('permission:purchase.inbound.list')->get('/purchase/inbounds/from-order/{orderId}', [PurchaseInboundController::class, 'fromOrder']);
        Route::middleware('permission:purchase.inbound.list')->get('/purchase/inbounds', [PurchaseInboundController::class, 'index']);
        Route::middleware('permission:purchase.inbound.create')->post('/purchase/inbounds', [PurchaseInboundController::class, 'store']);
        Route::middleware('permission:purchase.inbound.list')->get('/purchase/inbounds/{inbound}', [PurchaseInboundController::class, 'show']);
        Route::middleware('permission:purchase.inbound.update')->put('/purchase/inbounds/{inbound}', [PurchaseInboundController::class, 'update']);
        Route::middleware('permission:purchase.inbound.delete')->delete('/purchase/inbounds/{inbound}', [PurchaseInboundController::class, 'destroy']);
        Route::middleware('permission:purchase.inbound.update')->post('/purchase/inbounds/{inbound}/approve', [PurchaseInboundController::class, 'approve']);
    });

    // 销售订单：CRUD + 审核/关闭 + 可出库列表 + 出库记录（sales.order.*；审核/关闭复用 update）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:sales.order.list')->get('/sales/orders/available', [SalesOrderController::class, 'available']);
        Route::middleware('permission:sales.order.list')->get('/sales/orders', [SalesOrderController::class, 'index']);
        Route::middleware('permission:sales.order.create')->post('/sales/orders', [SalesOrderController::class, 'store']);
        Route::middleware('permission:sales.order.list')->get('/sales/orders/{order}', [SalesOrderController::class, 'show']);
        Route::middleware('permission:sales.order.update')->put('/sales/orders/{order}', [SalesOrderController::class, 'update']);
        Route::middleware('permission:sales.order.update')->post('/sales/orders/{order}/approve', [SalesOrderController::class, 'approve']);
        Route::middleware('permission:sales.order.update')->post('/sales/orders/{order}/close', [SalesOrderController::class, 'close']);
        Route::middleware('permission:sales.order.delete')->delete('/sales/orders/{order}', [SalesOrderController::class, 'destroy']);
        Route::middleware('permission:sales.order.list')->get('/sales/orders/{order}/outbounds', [SalesOrderController::class, 'outbounds']);
    });

    // 销售出库单：CRUD + from-order 预填 + 审核 + 当日出库汇总（sales.outbound.*；审核复用 update）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:sales.outbound.list')->get('/sales/outbounds/from-order/{orderId}', [SalesOutboundController::class, 'fromOrder']);
        Route::middleware('permission:sales.outbound.list')->get('/sales/outbounds/today-summary', [SalesOutboundController::class, 'todaySummary']);
        Route::middleware('permission:sales.outbound.list')->get('/sales/outbounds', [SalesOutboundController::class, 'index']);
        Route::middleware('permission:sales.outbound.create')->post('/sales/outbounds', [SalesOutboundController::class, 'store']);
        Route::middleware('permission:sales.outbound.list')->get('/sales/outbounds/{outbound}', [SalesOutboundController::class, 'show']);
        Route::middleware('permission:sales.outbound.update')->put('/sales/outbounds/{outbound}', [SalesOutboundController::class, 'update']);
        Route::middleware('permission:sales.outbound.delete')->delete('/sales/outbounds/{outbound}', [SalesOutboundController::class, 'destroy']);
        Route::middleware('permission:sales.outbound.update')->post('/sales/outbounds/{outbound}/approve', [SalesOutboundController::class, 'approve']);
    });

    // 生产工单：CRUD + 物料需求（production.order.*；下达/开工/完工/关闭复用 update，Task 4 追加）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:production.order.list')->get('/production/orders', [ProductionOrderController::class, 'index']);
        Route::middleware('permission:production.order.list')->get('/production/orders/{order}/materials', [ProductionOrderController::class, 'materials']);
        Route::middleware('permission:production.order.create')->post('/production/orders', [ProductionOrderController::class, 'store']);
        Route::middleware('permission:production.order.list')->get('/production/orders/{order}', [ProductionOrderController::class, 'show']);
        Route::middleware('permission:production.order.update')->put('/production/orders/{order}', [ProductionOrderController::class, 'update']);
        Route::middleware('permission:production.order.delete')->delete('/production/orders/{order}', [ProductionOrderController::class, 'destroy']);
        Route::middleware('permission:production.order.update')->post('/production/orders/{order}/release', [ProductionOrderController::class, 'release']);
        Route::middleware('permission:production.order.update')->post('/production/orders/{order}/start', [ProductionOrderController::class, 'start']);
        Route::middleware('permission:production.order.update')->post('/production/orders/{order}/complete', [ProductionOrderController::class, 'complete']);
        Route::middleware('permission:production.order.update')->post('/production/orders/{order}/close', [ProductionOrderController::class, 'close']);
    });

    // 工序报工：报工 + 记录列表（production.report.*；报工提交复用 create）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:production.report.create')->post('/production/operations/{operation}/reports', [OperationReportController::class, 'store']);
        Route::middleware('permission:production.report.list')->get('/production/operations/{operation}/reports', [OperationReportController::class, 'index']);
    });

    // 领料单：CRUD + from-order 预填 + 审核 + 发料（production.pick.*；审核/发料复用 update）
    Route::middleware('auth:sanctum')->group(function () {
        // 注意：from-order 必须先于 {pick} 注册，避免 orderId 被解析为领料单 ID
        Route::middleware('permission:production.pick.list')->get('/production/picks/from-order/{orderId}', [PickListController::class, 'fromOrder']);
        Route::middleware('permission:production.pick.list')->get('/production/picks', [PickListController::class, 'index']);
        Route::middleware('permission:production.pick.create')->post('/production/picks', [PickListController::class, 'store']);
        Route::middleware('permission:production.pick.list')->get('/production/picks/{pick}', [PickListController::class, 'show']);
        Route::middleware('permission:production.pick.update')->put('/production/picks/{pick}', [PickListController::class, 'update']);
        Route::middleware('permission:production.pick.delete')->delete('/production/picks/{pick}', [PickListController::class, 'destroy']);
        Route::middleware('permission:production.pick.update')->post('/production/picks/{pick}/approve', [PickListController::class, 'approve']);
        Route::middleware('permission:production.pick.update')->post('/production/picks/{pick}/issue', [PickListController::class, 'issue']);
    });

    // 退料单：CRUD + 审核（production.return.*；审核复用 update）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:production.return.list')->get('/production/returns', [ReturnListController::class, 'index']);
        Route::middleware('permission:production.return.create')->post('/production/returns', [ReturnListController::class, 'store']);
        Route::middleware('permission:production.return.list')->get('/production/returns/{return}', [ReturnListController::class, 'show']);
        Route::middleware('permission:production.return.update')->put('/production/returns/{return}', [ReturnListController::class, 'update']);
        Route::middleware('permission:production.return.delete')->delete('/production/returns/{return}', [ReturnListController::class, 'destroy']);
        Route::middleware('permission:production.return.update')->post('/production/returns/{return}/approve', [ReturnListController::class, 'approve']);
    });

    // 委外加工：CRUD + from-operation 预填 + 发出（审核）+ 回收 + 余料退回（production.outsource.*；审核/回收复用 update）
    Route::middleware('auth:sanctum')->group(function () {
        // from-operation 必须先于 {outsourcing} 注册（operationId 不被解析为委外单 ID）
        Route::middleware('permission:production.outsource.list')->get('/production/outsourcings/from-operation/{operationId}', [OutsourcingController::class, 'fromOperation']);
        Route::middleware('permission:production.outsource.list')->get('/production/outsourcings', [OutsourcingController::class, 'index']);
        Route::middleware('permission:production.outsource.create')->post('/production/outsourcings', [OutsourcingController::class, 'store']);
        Route::middleware('permission:production.outsource.list')->get('/production/outsourcings/{outsourcing}', [OutsourcingController::class, 'show']);
        Route::middleware('permission:production.outsource.update')->put('/production/outsourcings/{outsourcing}', [OutsourcingController::class, 'update']);
        Route::middleware('permission:production.outsource.delete')->delete('/production/outsourcings/{outsourcing}', [OutsourcingController::class, 'destroy']);
        Route::middleware('permission:production.outsource.update')->post('/production/outsourcings/{outsourcing}/approve', [OutsourcingController::class, 'approve']);
        Route::middleware('permission:production.outsource.update')->post('/production/outsourcings/{outsourcing}/receipts', [OutsourcingController::class, 'storeReceipt']);
        Route::middleware('permission:production.outsource.list')->get('/production/outsourcings/{outsourcing}/receipts', [OutsourcingController::class, 'receipts']);
        Route::middleware('permission:production.outsource.update')->post('/production/outsourcings/{outsourcing}/returns', [OutsourcingController::class, 'storeReturn']);
        Route::middleware('permission:production.outsource.list')->get('/production/outsourcings/{outsourcing}/returns', [OutsourcingController::class, 'returnList']);
    });

    // 成品入库单：CRUD + 审核（production.finished.*；审核复用 update）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:production.finished.list')->get('/production/finished-inbounds', [FinishedInboundController::class, 'index']);
        Route::middleware('permission:production.finished.create')->post('/production/finished-inbounds', [FinishedInboundController::class, 'store']);
        Route::middleware('permission:production.finished.list')->get('/production/finished-inbounds/{finishedInbound}', [FinishedInboundController::class, 'show']);
        Route::middleware('permission:production.finished.update')->put('/production/finished-inbounds/{finishedInbound}', [FinishedInboundController::class, 'update']);
        Route::middleware('permission:production.finished.delete')->delete('/production/finished-inbounds/{finishedInbound}', [FinishedInboundController::class, 'destroy']);
        Route::middleware('permission:production.finished.update')->post('/production/finished-inbounds/{finishedInbound}/approve', [FinishedInboundController::class, 'approve']);
    });

    // 统计报表：4 类只读聚合接口（report.inventory/movements/production/purchase_sales 四项查看权限）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:report.inventory')
            ->get('/reports/inventory-summary', [ReportController::class, 'inventorySummary']);
        Route::middleware('permission:report.movements')
            ->get('/reports/movements-summary', [ReportController::class, 'movementsSummary']);
        Route::middleware('permission:report.production')
            ->get('/reports/production', [ReportController::class, 'production']);
        Route::middleware('permission:report.purchase_sales')
            ->get('/reports/purchase-sales', [ReportController::class, 'purchaseSales']);
    });

    // 仪表盘：4 个只读聚合接口（dashboard.view——operator 亦持有，默认落地页全角色可见）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:dashboard.view')
            ->get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::middleware('permission:dashboard.view')
            ->get('/dashboard/pending-approvals', [DashboardController::class, 'pendingApprovals']);
        Route::middleware('permission:dashboard.view')
            ->get('/dashboard/work-order-progress', [DashboardController::class, 'workOrderProgress']);
        Route::middleware('permission:dashboard.view')
            ->get('/dashboard/alerts', [DashboardController::class, 'alerts']);
    });
});
