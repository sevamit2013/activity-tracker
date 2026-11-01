@echo off
setlocal enabledelayedexpansion

:: Launcher for the ActivityWatch bridge agent. One instance runs per logged-on
:: user; the agent itself decides which session owns ActivityWatch, so this
:: script must NOT filter on SESSIONNAME (that variable is fixed at logon and
:: reads "Console" for every local session, including switched-away ones).

set "BRIDGE_DIR=C:\Program Files\AWBridge"
set "PYTHON_EXE=%BRIDGE_DIR%\python\pythonw.exe"
if not exist "%PYTHON_EXE%" set "PYTHON_EXE=%BRIDGE_DIR%\python\python.exe"
if not exist "%PYTHON_EXE%" exit /b 1

:: Per-user storage, isolated from other users of this machine
if "%USERNAME%"=="" (
    for /f "tokens=*" %%u in ('whoami') do set "RAW_USER=%%u"
    for /f "tokens=2 delims=\" %%a in ("!RAW_USER!") do set "ACTIVE_USER=%%a"
) else (
    set "ACTIVE_USER=%USERNAME%"
)
set "USER_DIR=%PROGRAMDATA%\AWBridge\users\!ACTIVE_USER!"
if not exist "!USER_DIR!" mkdir "!USER_DIR!" 2>nul

:: The agent holds a session-scoped mutex and exits immediately if another
:: instance is already running in this session.
:restart_loop
cd /d "!USER_DIR!"
"%PYTHON_EXE%" "%BRIDGE_DIR%\aw_bridge_agent.py"
if errorlevel 3 exit /b 0
timeout /t 30 /nobreak >nul
goto :restart_loop
