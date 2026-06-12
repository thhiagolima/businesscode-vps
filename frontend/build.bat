@echo off
echo ══════════════════════════════════════
echo   BusinessCode — Production Build
echo ══════════════════════════════════════

echo [1/4] Building Vue app...
call npm run build
if errorlevel 1 (echo BUILD FAILED & exit /b 1)

echo [2/4] Renaming for production...
cd dist
if exist app.html del app.html
ren index.html app.html

echo [3/4] Copying landing page...
copy /Y ..\public\landing.html index.html >nul

echo [4/4] Installing .htaccess from template...
copy /Y ..\htaccess.template .htaccess >nul

cd ..
echo.
echo ══════════════════════════════════════
echo   Build complete!
echo   index.html = Landing page
echo   app.html   = Vue SPA (login, dashboard, etc)
echo   .htaccess  = Apache rewrite rules
echo ══════════════════════════════════════
