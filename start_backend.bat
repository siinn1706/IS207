@echo off
echo ================================
echo   Starting Backend (PHP / Laravel)
echo ================================
echo.

cd /d "%~dp0\KhoHang_API\php"

where php >nul 2>nul || (echo PHP 8.3+ is required: https://windows.php.net/download & pause & exit /b 1)
where composer >nul 2>nul || (echo Composer is required: https://getcomposer.org/download/ & pause & exit /b 1)

if not exist vendor (
    echo Installing PHP dependencies...
    call composer install --no-interaction
)
if not exist .env (
    copy .env.example .env >nul
    php artisan key:generate
)

echo Checking database schema (backup is taken before any migration)...
php artisan khohang:upgrade || (echo Schema upgrade failed - database left untouched. & pause & exit /b 1)

echo Starting realtime chat server (WebSocket ws://localhost:8001/ws/rt)...
start "KhoHang Realtime (WebSocket)" cmd /k php artisan rt:serve

echo Starting HTTP API at http://localhost:8000
echo.
php artisan serve --host=127.0.0.1 --port=8000

pause
