@echo off
echo ================================================
echo   BusinessCode - Starting All Services
echo ================================================

:: Backend API (porta 8000)
echo [1/7] Starting Laravel API on port 8000...
start "BusinessCode-API" /MIN cmd /c "cd /d C:\xampp\htdocs\new_saas\backend && C:\xampp\php\php.exe artisan serve --port=8000"
timeout /t 2 /nobreak > nul

:: Queue Worker (default) — emails, generic jobs
echo [2/7] Starting Queue Worker (default)...
start "BusinessCode-Worker" /MIN cmd /c "cd /d C:\xampp\htdocs\new_saas\backend && C:\xampp\php\php.exe artisan queue:work database --queue=default --sleep=3 --tries=3"

:: Queue Worker (campaigns) — batch send via ProcessCampaignJob
echo [3/7] Starting Queue Worker (campaigns)...
start "BusinessCode-Campaigns" /MIN cmd /c "cd /d C:\xampp\htdocs\new_saas\backend && C:\xampp\php\php.exe artisan queue:work database --queue=campaigns --sleep=3 --tries=3 --timeout=3600"

:: Queue Worker (messaging) — direct send SMS/voice/email via SendMessageJob
echo [4/7] Starting Queue Worker (messaging)...
start "BusinessCode-Messaging" /MIN cmd /c "cd /d C:\xampp\htdocs\new_saas\backend && C:\xampp\php\php.exe artisan queue:work database --queue=messaging --sleep=2 --tries=3 --timeout=300"

:: Queue Worker (billing) — MonthlyBillingJob + OverdueRetryJob
echo [5/7] Starting Queue Worker (billing)...
start "BusinessCode-Billing" /MIN cmd /c "cd /d C:\xampp\htdocs\new_saas\backend && C:\xampp\php\php.exe artisan queue:work database --queue=billing --sleep=5 --tries=3 --timeout=600"

:: Scheduler — dispara DispatchMonthlyBillingJob 03h, DispatchOverdueRetryJob 04h, etc.
echo [6/7] Starting Scheduler...
start "BusinessCode-Scheduler" /MIN cmd /c "cd /d C:\xampp\htdocs\new_saas\backend && C:\xampp\php\php.exe artisan schedule:work"

:: Frontend Vite (dev server porta 5173). Comente se voce serve dist/ via Apache.
echo [7/7] Starting Frontend (Vite)...
start "BusinessCode-Frontend" /MIN cmd /c "cd /d C:\xampp\htdocs\new_saas\frontend && npm run dev"

echo.
echo ================================================
echo   All services started!
echo   API:       http://localhost:8000
echo   Frontend:  http://localhost:5173
echo   Dashboard: https://dash.businesscode.com.br
echo.
echo   Queues running: default, campaigns, messaging, billing
echo   Scheduler:      billing.monthly-dispatch (03h), billing.overdue-retry (04h)
echo ================================================
echo.
echo Press any key to STOP all services...
pause > nul

:: Stop all
echo Stopping services...
taskkill /FI "WINDOWTITLE eq BusinessCode-*" /F > nul 2>&1
echo Done.
