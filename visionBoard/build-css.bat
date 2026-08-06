@echo off
REM Rebuilds the admin Tailwind stylesheet after editing any admin template.
REM Works fully offline - uses the bundled Tailwind CLI in tools\.
cd /d "%~dp0"
echo Building Tailwind CSS...
tools\tailwindcss.exe -c tailwind.config.js -i assets\css\tailwind.input.css -o assets\css\tailwind.css --minify
echo.
echo Done. Output: assets\css\tailwind.css
pause
