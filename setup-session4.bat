@echo off
setlocal
cd /d "%~dp0"
echo Student Services Information System - Session 4 setup
echo This setup uses php artisan migrate. It does NOT run migrate:fresh.
if not exist ".env" (
  if not exist ".env.example" (
    echo ERROR: .env.example is missing.
    exit /b 1
  )
  copy ".env.example" ".env" >nul
  echo Created .env from .env.example.
)
if not exist "database\database.sqlite" (
  type nul > "database\database.sqlite"
  echo Created an empty SQLite database.
)
php artisan key:generate
if errorlevel 1 goto failed
php artisan migrate
if errorlevel 1 goto failed
npm run build
if errorlevel 1 goto failed
echo.
echo Setup finished. Start the app with: php artisan serve
echo Then open http://127.0.0.1:8000
exit /b 0
:failed
echo.
echo Setup stopped because a command failed. Read the error above before retrying.
exit /b 1
