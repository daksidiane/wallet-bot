@echo off
setlocal EnableExtensions
chcp 65001 >nul
cd /d "%~dp0"

REM Double-click diagnostics: bot - Telegram connection. No secret output.
REM  check_telegram.bat     - show status
REM  check_telegram.bat clear - reset a stale webhook

if exist "php\php.exe" goto :php_ok
echo [ERROR] php\php.exe is missing. Put PHP 8+ into the "php" folder.
pause
exit /b 1
:php_ok

set "PHP_EXT_LINK=%LOCALAPPDATA%\walletbot_php_ext"
if exist "%PHP_EXT_LINK%\" rmdir "%PHP_EXT_LINK%" >nul 2>&1
mklink /J "%PHP_EXT_LINK%" "%CD%\php\ext" >nul 2>&1
copy /Y "%CD%\cacert.pem" "%LOCALAPPDATA%\walletbot_cacert.pem" >nul 2>&1

"%CD%\php\php.exe" -d curl.cainfo=%LOCALAPPDATA%\walletbot_cacert.pem -d extension_dir=%PHP_EXT_LINK% -d extension=curl -d extension=mbstring -d extension=openssl -d extension=pdo_sqlite -d extension=sqlite3 check_telegram.php %1
echo.
pause