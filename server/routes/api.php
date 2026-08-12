<?php

// API 路由：/api/v1 前缀，认证路由公开，业务路由挂 auth:sanctum + 权限中间件
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BomController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CheckController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DictionaryController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ProcessController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RoleController;
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
        Route::middleware('permission:user.update')->put('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
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

    // 数据字典：按编码取值登录即可访问（供其他模块下拉）；CRUD 要求认证 + 对应权限（dictionary.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        // 注意：code/{code} 必须先于 {dictionary} 注册，避免 code 被解析为字典 ID
        Route::get('/dictionaries/code/{code}', [DictionaryController::class, 'byCode']);
        Route::middleware('permission:dictionary.list')->get('/dictionaries', [DictionaryController::class, 'index']);
        Route::middleware('permission:dictionary.list')->get('/dictionaries/{dictionary}/items', [DictionaryController::class, 'items']);
        Route::middleware('permission:dictionary.create')->post('/dictionaries', [DictionaryController::class, 'store']);
        Route::middleware('permission:dictionary.create')
            ->post('/dictionaries/{dictionary}/items', [DictionaryController::class, 'storeItem']);
        Route::middleware('permission:dictionary.update')->put('/dictionaries/{dictionary}', [DictionaryController::class, 'update']);
        Route::middleware('permission:dictionary.update')->put('/dictionaries/items/{item}', [DictionaryController::class, 'updateItem']);
        Route::middleware('permission:dictionary.delete')->delete('/dictionaries/{dictionary}', [DictionaryController::class, 'destroy']);
        Route::middleware('permission:dictionary.delete')
            ->delete('/dictionaries/items/{item}', [DictionaryController::class, 'destroyItem']);
    });

    // 分类：树形列表 + CRUD（category.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:category.list')->get('/categories', [CategoryController::class, 'index']);
        Route::middleware('permission:category.create')->post('/categories', [CategoryController::class, 'store']);
        Route::middleware('permission:category.update')->put('/categories/{category}', [CategoryController::class, 'update']);
        Route::middleware('permission:category.delete')->delete('/categories/{category}', [CategoryController::class, 'destroy']);
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
        Route::middleware('permission:process.update')->put('/processes/{process}', [ProcessController::class, 'update']);
        Route::middleware('permission:process.delete')->delete('/processes/{process}', [ProcessController::class, 'destroy']);
    });

    // 仓库/库位：CRUD + 库位子资源（warehouse.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:warehouse.list')->get('/warehouses', [WarehouseController::class, 'index']);
        Route::middleware('permission:warehouse.create')->post('/warehouses', [WarehouseController::class, 'store']);
        Route::middleware('permission:warehouse.update')->put('/warehouses/{warehouse}', [WarehouseController::class, 'update']);
        Route::middleware('permission:warehouse.delete')->delete('/warehouses/{warehouse}', [WarehouseController::class, 'destroy']);
        Route::middleware('permission:warehouse.list')->get('/warehouses/{warehouse}/locations', [WarehouseController::class, 'locations']);
        Route::middleware('permission:warehouse.create')
            ->post('/warehouses/{warehouse}/locations', [WarehouseController::class, 'storeLocation']);
        Route::middleware('permission:warehouse.update')->put('/locations/{location}', [WarehouseController::class, 'updateLocation']);
        Route::middleware('permission:warehouse.delete')->delete('/locations/{location}', [WarehouseController::class, 'destroyLocation']);
    });

    // 供应商：CRUD（supplier.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:supplier.list')->get('/suppliers', [SupplierController::class, 'index']);
        Route::middleware('permission:supplier.create')->post('/suppliers', [SupplierController::class, 'store']);
        Route::middleware('permission:supplier.update')->put('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::middleware('permission:supplier.delete')->delete('/suppliers/{supplier}', [SupplierController::class, 'destroy']);
    });

    // 客户：CRUD（customer.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:customer.list')->get('/customers', [CustomerController::class, 'index']);
        Route::middleware('permission:customer.create')->post('/customers', [CustomerController::class, 'store']);
        Route::middleware('permission:customer.update')->put('/customers/{customer}', [CustomerController::class, 'update']);
        Route::middleware('permission:customer.delete')->delete('/customers/{customer}', [CustomerController::class, 'destroy']);
    });

    // 商品：CRUD + 扫码查询（product.list/create/update/delete）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:product.list')->get('/products', [ProductController::class, 'index']);
        Route::middleware('permission:product.list')->get('/products/barcode/{barcode}', [ProductController::class, 'byBarcode']);
        Route::middleware('permission:product.create')->post('/products', [ProductController::class, 'store']);
        Route::middleware('permission:product.update')->put('/products/{product}', [ProductController::class, 'update']);
        Route::middleware('permission:product.delete')->delete('/products/{product}', [ProductController::class, 'destroy']);
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

    // 库存查询：余额/导出/流水/预警（inventory.list）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:inventory.list')->get('/inventory/balances', [InventoryController::class, 'balances']);
        Route::middleware('permission:inventory.list')->get('/inventory/balances/export', [InventoryController::class, 'exportBalances']);
        Route::middleware('permission:inventory.list')->get('/inventory/movements', [InventoryController::class, 'movements']);
        Route::middleware('permission:inventory.list')->get('/inventory/alerts', [InventoryController::class, 'alerts']);
    });

    // 盘点单：CRUD + 账面预填 + 审核（check.list/create/update/delete；审核复用 check.update）
    Route::middleware('auth:sanctum')->group(function () {
        Route::middleware('permission:check.list')->get('/checks/auto-books', [CheckController::class, 'autoBooks']);
        Route::middleware('permission:check.list')->get('/checks', [CheckController::class, 'index']);
        Route::middleware('permission:check.create')->post('/checks', [CheckController::class, 'store']);
        Route::middleware('permission:check.list')->get('/checks/{check}', [CheckController::class, 'show']);
        Route::middleware('permission:check.update')->put('/checks/{check}', [CheckController::class, 'update']);
        Route::middleware('permission:check.delete')->delete('/checks/{check}', [CheckController::class, 'destroy']);
        Route::middleware('permission:check.update')->post('/checks/{check}/approve', [CheckController::class, 'approve']);
    });
});
