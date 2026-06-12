@echo off
REM Push branch fix/ui-sprint1-banner-plans-role-mobile e abre o link do PR.
REM Gerado pela auditoria /ui-ux-pro-max — Sprint 1 (5 bugs).

cd /d C:\xampp\htdocs
echo.
echo === Push branch para origin ===
git push -u origin fix/ui-sprint1-banner-plans-role-mobile
if errorlevel 1 (
  echo.
  echo Push falhou. Verifique sua autenticacao Bitbucket.
  pause
  exit /b 1
)

echo.
echo === Abrindo Bitbucket para criar o PR ===
start "" "https://bitbucket.org/ddi_webex/businesscodev2/pull-requests/new?source=fix/ui-sprint1-banner-plans-role-mobile&dest=main&t=1"
echo.
echo Cole a descricao de new_saas\PR_DESCRIPTION.md no body do PR.
echo.
pause
