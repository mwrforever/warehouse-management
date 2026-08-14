@echo off
rem warehouse 一键启动（Windows 双击入口，实际执行 start-dev.sh）
rem 前置：已安装 Git Bash、Docker Desktop 已启动
cd /d "%~dp0"
where bash >nul 2>nul
if errorlevel 1 (
    echo [错误] 未找到 Git Bash，请安装 Git for Windows 后重试
    pause
    exit /b 1
)
bash start-dev.sh
