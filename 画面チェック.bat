@echo off
rem ===== ECS check (run this BEFORE deploy) =====
rem Double click this file. It checks every screen and every feature.
rem
rem WHY: When the JavaScript in a screen breaks, the page still looks normal.
rem      It does NOT go white. Buttons and tabs just stop working, tables go empty.
rem      You cannot notice it by looking. This check finds it.
rem      (Happened in production on 2026-08-26 / 08-28 / 08-31.)
rem
rem SAFE: Tests use a throwaway in-memory database. Real data is never touched.
cd /d "%~dp0"
set PHP="C:\Users\onuma\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe"

echo.
echo ==========================================
echo  ECS check wo hajimemasu.
echo  1 pun gurai kakarimasu. Sono mama omachi kudasai.
echo  (Honmono no data ni wa sawarimasen)
echo ==========================================
echo.

%PHP% artisan view:clear >nul 2>&1
%PHP% artisan test

echo.
if errorlevel 1 (
  echo ##########################################
  echo  NG : doko ka kowarete imasu.
  echo.
  echo       DEPLOY SHINAIDE KUDASAI.
  echo       Ue no akai moji wo Claude ni misete kudasai.
  echo ##########################################
) else (
  echo ==========================================
  echo  OK : zenbu daijoubu desu.
  echo       Deploy shite daijoubu desu.
  echo ==========================================
)
echo.
pause
