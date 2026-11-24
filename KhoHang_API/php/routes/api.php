<?php

/*
 * HTTP contract of the former FastAPI backend (KhoHang_API/app/*.py).
 * Registered without the default "/api" prefix (see bootstrap/app.php) so every
 * URL, including /api/chatbot/*, stays exactly where the React/Tauri client expects it.
 * Full matrix: plans/260924-0634-migrate-khohang-api-to-php/contract-matrix.md
 */

use App\Http\Controllers\AuthController as A;
use App\Http\Controllers\CatalogController as C;
use App\Http\Controllers\CoreController as Core;
use App\Http\Controllers\RtController as Rt;
use App\Http\Controllers\StockController as S;
use App\Http\Controllers\UserController as U;
use Illuminate\Support\Facades\Route;

// ---- root / static ----
Route::get('/', [Core::class, 'root']);
Route::get('/debug/paths', [Core::class, 'debugPaths']);
Route::get('/download/{folder}/{filename}', [Core::class, 'download']);
Route::get('/uploads/{path}', [Core::class, 'uploads'])->where('path', '.*');

// ---- auth (auth_routes.py) ----
Route::post('/auth/register/request-otp', [A::class, 'registerRequestOtp']);
Route::post('/auth/register/confirm', [A::class, 'registerConfirm']);
Route::post('/auth/login', [A::class, 'login']);
Route::get('/auth/me', [A::class, 'me']);
Route::post('/auth/password/request-otp', [A::class, 'passwordRequestOtp']);
Route::post('/auth/password/confirm', [A::class, 'passwordConfirm']);
Route::post('/auth/change-password', [A::class, 'changePassword']);
Route::post('/auth/logout', [A::class, 'logout']);
Route::post('/auth/passkey/request-otp', [A::class, 'passkeyRequestOtp']);
Route::post('/auth/passkey/confirm', [A::class, 'passkeyConfirm']);

// ---- users (user_routes.py) ----
Route::get('/users', [U::class, 'search']);
Route::get('/users/me', [U::class, 'me']);
Route::patch('/users/me', [U::class, 'updateMe']);
Route::post('/users/me/avatar', [U::class, 'uploadAvatar']);
Route::delete('/users/me/avatar', [U::class, 'deleteAvatar']);

// ---- chatbot (chatbot_routes.py) ----
Route::get('/api/chatbot/config', [Core::class, 'chatbotConfig']);
Route::put('/api/chatbot/config', [Core::class, 'updateChatbotConfig']);
Route::post('/api/chatbot/avatar', [Core::class, 'chatbotAvatar']);
Route::post('/api/chatbot/files', [Core::class, 'chatbotFile']);

// ---- realtime chat REST (rt_chat_routes.py) ----
Route::get('/rt/conversations', [Rt::class, 'conversations']);
Route::get('/rt/conversations/pending', [Rt::class, 'pending']);
Route::post('/rt/conversations/direct', [Rt::class, 'direct']);
Route::get('/rt/conversations/{conversationId}/messages', [Rt::class, 'messages']);
Route::post('/rt/conversations/{conversationId}/accept', [Rt::class, 'accept']);
Route::post('/rt/conversations/{conversationId}/reject', [Rt::class, 'reject']);
Route::get('/rt/conversations/{conversationId}/pinned', [Rt::class, 'pinned']);
Route::post('/rt/conversations/{conversationId}/pin/{messageId}', [Rt::class, 'pin']);
Route::delete('/rt/conversations/{conversationId}/pin/{messageId}', [Rt::class, 'unpin']);
Route::post('/rt/files', [Rt::class, 'files']);
Route::get('/rt/users/lookup', [Rt::class, 'lookup']);
Route::post('/rt/messages/{messageId}/reactions', [Rt::class, 'addReaction']);
Route::get('/rt/messages/{messageId}/reactions', [Rt::class, 'reactions']);
Route::delete('/rt/messages/{messageId}/reactions/{emoji}', [Rt::class, 'removeReaction']);

// ---- suppliers ----
Route::get('/suppliers', [C::class, 'suppliers']);
Route::post('/suppliers', [C::class, 'createSupplier']);
Route::put('/suppliers/{supplierId}', [C::class, 'updateSupplier']);
Route::delete('/suppliers/{supplierId}', [C::class, 'deleteSupplier']);
Route::get('/suppliers/{supplierId}/transactions', [C::class, 'supplierTransactions']);

// ---- items ----
Route::get('/items', [C::class, 'items']);
Route::post('/items', [C::class, 'createItem']);
Route::get('/items/alerts', [C::class, 'alerts']);
Route::get('/items/top-items', [C::class, 'topItems']);
Route::get('/items/monthly-trend', [C::class, 'itemsMonthlyTrend']);
Route::get('/items/category-distribution', [C::class, 'categoryDistribution']);
Route::put('/items/{itemId}', [C::class, 'updateItem']);
Route::delete('/items/{itemId}', [C::class, 'deleteItem']);

// ---- search / transactions / dashboard ----
Route::get('/search/global', [C::class, 'globalSearch']);
Route::get('/stock/transactions', [C::class, 'transactions']);
Route::post('/stock/transactions', [C::class, 'createTransaction']);
Route::get('/dashboard/stats', [C::class, 'dashboardStats']);

// ---- AI ----
Route::post('/ai/chat', [Core::class, 'aiChat']);
Route::post('/ai/chat-md', [Core::class, 'aiChatMarkdown']);

// ---- stock vouchers ----
Route::get('/stock/in', [S::class, 'stockIn']);
Route::post('/stock/in', [S::class, 'createStockIn']);
Route::get('/stock/in/{recordId}', [S::class, 'stockInOne']);
Route::delete('/stock/in/{recordId}', [S::class, 'deleteStockIn']);
Route::get('/stock/out', [S::class, 'stockOut']);
Route::post('/stock/out', [S::class, 'createStockOut']);
Route::get('/stock/out/{recordId}', [S::class, 'stockOutOne']);
Route::delete('/stock/out/{recordId}', [S::class, 'deleteStockOut']);

// ---- export ----
Route::post('/export/excel', [Core::class, 'exportExcel']);
Route::post('/export/pdf', [Core::class, 'exportPdf']);

// ---- company ----
Route::post('/company/upload-logo', [S::class, 'uploadLogo']);
Route::get('/company', [S::class, 'company']);
Route::post('/company', [S::class, 'createOrUpdateCompany']);
Route::put('/company', [S::class, 'updateCompany']);

// ---- warehouses ----
Route::get('/warehouses', [S::class, 'warehouses']);
Route::get('/warehouses/active', [S::class, 'activeWarehouse']);
Route::post('/warehouses', [S::class, 'createWarehouse']);
Route::put('/warehouses/{warehouseId}', [S::class, 'updateWarehouse']);
Route::delete('/warehouses/{warehouseId}', [S::class, 'deleteWarehouse']);
Route::put('/warehouses/{warehouseId}/set-active', [S::class, 'setActiveWarehouse']);
Route::get('/warehouses/{warehouseId}/inventory', [S::class, 'warehouseInventory']);

// ---- reports ----
Route::get('/reports/inventory-by-category', [S::class, 'inventoryByCategory']);
Route::get('/reports/monthly-trend', [S::class, 'monthlyTrendReport']);
Route::get('/reports/low-stock-items', [S::class, 'lowStockItems']);

// ---- chatbot history & preferences (main.py) ----
Route::get('/chat/messages/{conversationId}', [U::class, 'chatMessages']);
Route::post('/chat/messages', [U::class, 'createChatMessage']);
Route::put('/chat/messages/{messageId}/reactions', [U::class, 'updateReactions']);
Route::delete('/chat/messages/{conversationId}', [U::class, 'clearChat']);
Route::get('/user/preferences', [U::class, 'preferences']);
Route::put('/user/preferences', [U::class, 'updatePreferences']);
