@echo off
rem ===== ECS gamen check (run this BEFORE deploy) =====
rem Double click this file. It checks that every screen's JavaScript is not broken.
rem
rem WHY: When the JavaScript in a screen breaks, the page still looks normal.
rem      It does NOT go white. Buttons and tabs just stop working and tables go empty.
rem      You cannot notice it by looking. This check finds it.
rem      (Happened in production on 2026-08-26 / 08-28 / 08-31.)
cd /d "%~dp0"
set PHP="C:\Users\onuma\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"

echo.
echo ==========================================
echo  ECS gamen check wo hajimemasu...
echo  (zenbu no gamen no JavaScript wo shiraberu)
echo ==========================================
echo.

%PHP% artisan view:clear >nul 2>&1
%PHP% artisan test --filter="RenderedScriptSyntax|BladeDirectiveLeak|BladeScriptEscape|JsSyntaxCheckTest|BrokenTextDoesNotKillScript|MastersContentCleanup|DeployWithoutMigration"

echo.
if errorlevel 1 (
  echo ##########################################
  echo  NG : gamen ga kowarete imasu.
  echo       DEPLOY SHINAIDE KUDASAI.
  echo       Ue no akai moji wo Claude ni misete kudasai.
  echo ##########################################
) else (
  echo ==========================================
  echo  OK : zenbu no gamen wa daijoubu desu.
  echo       Deploy shite daijoubu desu.
  echo ==========================================
)
echo.
pause
