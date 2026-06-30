@echo off
rem ===== ECS (Laravel) kantan kidou file =====
rem Double click this file to start the app. Browser opens automatically.
rem Stop the app: click this black window and press Ctrl + C.
cd /d "%~dp0"
echo ECS wo kidou shimasu... (browser ga hiraku made sukoshi machimasu)
echo URL: http://127.0.0.1:8000
start "" http://127.0.0.1:8000
"C:\Users\onuma\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe" artisan serve
pause
