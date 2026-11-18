@echo off
title CCTV recorder - leave this window open
cd /d "%~dp0"
echo Starting the CCTV recorder...
"C:\xampp\php\php.exe" recorder.php
echo.
echo The recorder stopped. See the message above.
pause
