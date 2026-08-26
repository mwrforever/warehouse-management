@echo off
setlocal
cd /d "%~dp0"
set "ROOT=%~dp0"
set "ROOT=%ROOT:~0,-1%"

rem ============================================================
rem Warehouse Management System - one-click dev startup (Windows)
rem Starts: Docker MySQL(:3306) -> Backend API :7000 -> Frontend :4000 -> browser
rem Stop   : close the two "Backend"/"Frontend" windows, or press Ctrl+C
rem Note   : pure batch, no Git Bash / start-dev.sh dependency (sh kept intact)
rem All text is ASCII to avoid codepage issues.
rem ============================================================

rem ---- 1/6 locate dev toolchain (this machine keeps tools under D:\code\envs) ----
set "PHP_DIR="
for /d %%G in ("D:\code\envs\php\*") do set "PHP_DIR=%%G"
if not defined PHP_DIR (
    echo [ERROR] PHP dir D:\code\envs\php\* not found. Check install location or edit this script.
    pause
    exit /b 1
)
set "PATH=%PHP_DIR%;D:\code\envs\nodejs;D:\code\envs\composer;%PATH%"

rem ---- 2/6 start MySQL (Docker container php-design-mysql) ----
echo ==^> [2/6] Starting MySQL (Docker container php-design-mysql)...
docker compose up -d mysql
if errorlevel 1 goto :dockerfail

echo ==^> Waiting for MySQL to be ready (up to 30s)...
if not defined MYSQL_ROOT_PASSWORD set "MYSQL_ROOT_PASSWORD=root"
set "MYSQL_READY=0"
for /l %%i in (1,1,30) do (
    docker exec php-design-mysql mysqladmin ping -u root -p"%MYSQL_ROOT_PASSWORD%" --silent >nul 2>&1
    if not errorlevel 1 (
        set "MYSQL_READY=1"
        goto :mysql_ok
    )
    ping -n 2 127.0.0.1 >nul
)
:mysql_ok
if "%MYSQL_READY%" neq "1" (
    echo [ERROR] MySQL not ready in 30s. Check: docker logs php-design-mysql
    echo          If root password was hardened, pass it via env MYSQL_ROOT_PASSWORD must match docker-compose.yml.
    pause
    exit /b 1
)
echo     MySQL is ready

rem ---- 3/6 init database (migrate + seed, idempotent) ----
echo ==^> [3/6] Initializing database (migrate + seed)...
if not defined ADMIN_PASSWORD set "ADMIN_PASSWORD=admin123"
cd /d "%ROOT%\server"
php artisan migrate --seed
if errorlevel 1 (
    echo [ERROR] Database init failed. Check server/.env database settings.
    pause
    exit /b 1
)
cd /d "%ROOT%"

rem ---- 4/6 port pre-check (7000 backend / 4000 frontend) ----
echo ==^> [4/6] Port pre-check...
netstat -ano | findstr /R /C:":7000 " | findstr "LISTENING" >nul 2>&1
if not errorlevel 1 (
    echo [ERROR] Port 7000 is in use service may already be running. Stop it first and retry.
    pause
    exit /b 1
)
netstat -ano | findstr /R /C:":4000 " | findstr "LISTENING" >nul 2>&1
if not errorlevel 1 (
    echo [ERROR] Port 4000 is in use service may already be running. Stop it first and retry.
    pause
    exit /b 1
)

rem ---- 5/6 start backend & frontend (separate windows, live logs) ----
echo ==^> [5/6] Starting backend :7000 and frontend :4000 (first frontend boot compiles, 10-30s)...
start "Backend API :7000" cmd /k "cd /d "%ROOT%\server" && php artisan serve --host=127.0.0.1 --port=7000"
start "Frontend :4000" cmd /k "cd /d "%ROOT%\web" && npm run dev"

rem ---- 6/6 readiness probe + open browser ----
echo ==^> [6/6] Waiting for services to be ready...
set "BACK_READY=0"
for /l %%i in (1,1,60) do (
    curl -sf http://127.0.0.1:7000 >nul 2>&1
    if not errorlevel 1 (
        set "BACK_READY=1"
        goto :back_ok
    )
    ping -n 2 127.0.0.1 >nul
)
:back_ok
set "FRONT_READY=0"
for /l %%i in (1,1,90) do (
    curl -sf http://localhost:4000 >nul 2>&1
    if not errorlevel 1 (
        set "FRONT_READY=1"
        goto :front_ok
    )
    ping -n 2 127.0.0.1 >nul
)
:front_ok
if "%BACK_READY%" neq "1" (
    echo [ERROR] Backend :7000 not ready in 60s. Check the Backend window output port conflict or startup error.
    pause
    exit /b 1
)
if "%FRONT_READY%" neq "1" (
    echo [ERROR] Frontend :4000 not ready in 90s. Check the Frontend window output compile error or port conflict.
    pause
    exit /b 1
)

rem open default browser
start "" "http://localhost:4000"

echo.
echo ==============================================
echo   Frontend UI : http://localhost:4000
echo   Backend API : http://127.0.0.1:7000
echo   Login       : admin / %ADMIN_PASSWORD%
echo   Stop        : close the "Backend API :7000" and "Frontend :4000" windows
echo ==============================================
echo.
echo Startup finished! Closing this window will NOT stop the services.
pause
exit /b 0

:dockerfail
echo [ERROR] Docker is not available. Start Docker Desktop first, then rerun this script.
pause
exit /b 1
