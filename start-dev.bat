@echo off
setlocal
cd /d "%~dp0"

rem ============================================================
rem warehouse one-click start (Windows double-click entry)
rem It locates Git Bash and this machine's dev tools, then runs
rem start-dev.sh. All text is ASCII to avoid codepage issues.
rem ============================================================

rem ---- locate Git Bash (where -> standard installs -> D:\code\envs) ----
set "BASH="
where bash >nul 2>nul && set "BASH=bash"
if not defined BASH if exist "C:\Program Files\Git\bin\bash.exe" set "BASH=C:\Program Files\Git\bin\bash.exe"
if not defined BASH if exist "%LOCALAPPDATA%\Programs\Git\bin\bash.exe" set "BASH=%LOCALAPPDATA%\Programs\Git\bin\bash.exe"
if not defined BASH for /d %%G in ("D:\code\envs\git\*") do if exist "%%G\usr\bin\bash.exe" set "BASH=%%G\usr\bin\bash.exe"
if not defined BASH (
    echo [ERROR] Git Bash not found. Install Git for Windows or edit the BASH paths in start-dev.bat.
    pause
    exit /b 1
)

rem ---- append local dev tools to PATH (this machine keeps them under D:\code\envs) ----
rem php / node / composer / git; docker desktop under LOCALAPPDATA
for /d %%G in ("D:\code\envs\php\*") do set "PATH=%%G;%PATH%"
for /d %%G in ("D:\code\envs\nodejs\*") do set "PATH=%%G;%PATH%"
for /d %%G in ("D:\code\envs\composer\*") do set "PATH=%%G;%PATH%"
for /d %%G in ("D:\code\envs\git\*") do set "PATH=%%G\usr\bin;%PATH%"
if exist "%LOCALAPPDATA%\Programs\DockerDesktop\resources\bin" set "PATH=%LOCALAPPDATA%\Programs\DockerDesktop\resources\bin;%PATH%"

rem ---- hand over to the real launcher ----
"%BASH%" start-dev.sh
pause
