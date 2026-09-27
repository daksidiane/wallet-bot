@echo off
setlocal EnableExtensions
chcp 65001 >nul
title Finance Bot
cd /d "%~dp0"

REM ====================================================================
REM  One-click launcher for the Telegram bot (long polling).
REM
REM  Requirements:
REM    - PHP 8+ in PATH, OR a portable PHP at "php\php.exe" next to this
REM      file, OR the full path in the PHP_BIN env variable.
REM    - secrets.php exists, see secrets.example.php.
REM
REM  Stop: press Ctrl+C or close this window.
REM ====================================================================

REM --- 1. secrets.php exists? -----------------------------------------------
if exist "secrets.php" goto :check_php
echo [ERROR] Missing secrets.php with the bot token.
echo.
echo Fix:
echo   copy secrets.example.php secrets.php
echo and paste the token from BotFather into secrets.php.
echo.
pause
exit /b 1

REM --- 2. Find and validate PHP ----------------------------------------------
:check_php
set "PHP_EXE="
set "PHP_BUNDLED="
if exist "php\php.exe" set "PHP_BUNDLED=1"
if defined PHP_BUNDLED set "PHP_EXE=%CD%\php\php.exe"
if not defined PHP_EXE if defined PHP_BIN if exist "%PHP_BIN%" set "PHP_EXE=%PHP_BIN%"
if not defined PHP_EXE if exist "C:\php\php.exe" set "PHP_EXE=C:\php\php.exe"
if not defined PHP_EXE if exist "C:\xampp\php\php.exe" set "PHP_EXE=C:\xampp\php\php.exe"
if not defined PHP_EXE if exist "%ProgramFiles%\php\php.exe" set "PHP_EXE=%ProgramFiles%\php\php.exe"
if not defined PHP_EXE if exist "%LOCALAPPDATA%\Programs\php\php.exe" set "PHP_EXE=%LOCALAPPDATA%\Programs\php\php.exe"
for /f "delims=" %%p in ('where php 2^>nul') do if not defined PHP_EXE set "PHP_EXE=php"
if defined PHP_EXE goto :php_found

echo [ERROR] PHP was not found.
echo.
echo Options:
echo   - install PHP and add it to PATH,
echo   - put portable PHP in the "php" folder next to this file,
echo     so that bot\php\php.exe exists,
echo   - set the full path in the PHP_BIN env variable.
echo.
pause
exit /b 1

:php_found
set "PHP_FLAGS="
if not defined PHP_BUNDLED goto :php_check
REM Bundled PHP: this Windows build cannot open extensions from a
REM non-ASCII path, so expose the ext folder via an ASCII junction.
set "PHP_EXT_LINK=%LOCALAPPDATA%\walletbot_php_ext"
if exist "%PHP_EXT_LINK%\" rmdir "%PHP_EXT_LINK%" >nul 2>&1
mklink /J "%PHP_EXT_LINK%" "%CD%\php\ext" >nul 2>&1
copy /Y "%CD%\cacert.pem" "%LOCALAPPDATA%\walletbot_cacert.pem" >nul 2>&1
set "PHP_FLAGS=-d curl.cainfo=%LOCALAPPDATA%\walletbot_cacert.pem -d extension_dir=%PHP_EXT_LINK% -d extension=openssl -d extension=curl -d extension=mbstring -d extension=pdo_sqlite -d extension=sqlite3"
:php_check
"%PHP_EXE%" -v >nul 2>&1
if errorlevel 1 goto :php_broken
"%PHP_EXE%" -v 2>nul | findstr /R /C:"PHP 8" >nul
if not errorlevel 1 goto :php_version_ok
"%PHP_EXE%" -v 2>nul | findstr /R /C:"PHP 9" >nul
if not errorlevel 1 goto :php_version_ok
echo [ERROR] PHP 8+ is required, found older PHP at "%PHP_EXE%".
echo.
pause
exit /b 1

:php_broken
echo [ERROR] PHP cannot be launched: "%PHP_EXE%".
echo It may be a dummy stub from Microsoft Store. Install real PHP 8+.
echo.
pause
exit /b 1

REM --- 3. Init DB schema, safe to repeat --------------------------------------
:php_version_ok
echo [1/2] Checking DB schema...
"%PHP_EXE%" %PHP_FLAGS% scripts\init_schema.php
if errorlevel 1 goto :schema_fail

REM --- 4. Run the bot -----------------------------------------------------------
echo [2/2] Starting the bot. Stop: press Ctrl+C or close this window.
"%PHP_EXE%" %PHP_FLAGS% bot.php

set "EXIT_CODE=%errorlevel%"
echo.
echo Bot stopped, exit code %EXIT_CODE%.
pause
exit /b %EXIT_CODE%

:schema_fail
echo.
echo [ERROR] Failed to create or check tables. See message above.
echo.
pause
exit /b 1