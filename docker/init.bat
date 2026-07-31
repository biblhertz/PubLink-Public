@echo off
setlocal EnableDelayedExpansion

echo ============================================
echo  Publink Setup
echo ============================================
echo.

:: ============================================
:: Defaults
:: ============================================
set SITE_ROOT=http://localhost
set REGISTRATION_ENABLED=true
set CROSSREF_INTEGRATION=true
set CROSSREF_EMAIL=
set GOOGLE_BOOKS_API_KEY=
set GOOGLE_BOOKS_INTEGRATION=false
set GOOGLE_BOOKS_API_KEY=
set MANIFEST_SERVER_INTEGRATION=false
set MANIFEST_SERVER_URI=
set ORCID_INTEGRATION=false
set ORCID_CLIENT_ID=
set ORCID_CLIENT_SECRET=
set ORCID_OATH_ADDRESS=https://orcid.org/oauth/token
set ORCID_API_ADDRESS=https://api.orcid.org/v3.0/
set PRIMO_INTEGRATION=false
set PRIMO_API_KEY=
set PRIMO_VID=
set PRIMO_URI=
set PUBLICATION=false
set TEIPUB_INTEGRATION=false
set TEIPUB_URI=
set MIRADOR_CLIENT=
set ADMIN_EMAIL=

:: Load saved config if available
if exist init.cfg (
    for /f "usebackq eol=# tokens=1,* delims==" %%a in ("init.cfg") do set %%a=%%b
    echo Loaded settings from init.cfg
    echo.
)

:: ============================================
:: Site Settings
:: ============================================
echo ============================================
echo  Site Settings
echo ============================================
echo.
set /p SITE_ROOT=Site root URL [%SITE_ROOT%]:
if "%SITE_ROOT%"=="" set SITE_ROOT=http://localhost
if "%MIRADOR_CLIENT%"=="" set MIRADOR_CLIENT=%SITE_ROOT%:3000
set /p MIRADOR_CLIENT=Mirador client URL [%MIRADOR_CLIENT%]:
set _DISP=N
if /i "%REGISTRATION_ENABLED%"=="true" set _DISP=Y
set _ANS=%_DISP%
set /p _ANS=Allow users to self-register via the registration form [%_DISP%]:
call :norm_bool REGISTRATION_ENABLED

:: ============================================
:: Auto-generate secrets (always fresh)
:: ============================================
for /f "usebackq delims=" %%k in (`powershell -NoProfile -Command "[System.BitConverter]::ToString([Security.Cryptography.RandomNumberGenerator]::GetBytes(16)).Replace('-','')"`) do set DB_PASSWORD=%%k
for /f "usebackq delims=" %%k in (`powershell -NoProfile -Command "[System.BitConverter]::ToString([Security.Cryptography.RandomNumberGenerator]::GetBytes(32)).Replace('-','')"`) do set ENCRYPT_KEY=%%k
powershell -NoProfile -Command "(Get-Content 'mysql\my.cnf') -replace '^password=.*', 'password=%DB_PASSWORD%' | Set-Content 'mysql\my.cnf'"
echo Database password generated.
echo Encryption key generated.
echo.

:: ============================================
:: Integration Setup
:: ============================================
echo ============================================
echo  Integration Setup
echo ============================================
echo.

:: --- CrossRef ---
set _DISP=N
if /i "%CROSSREF_INTEGRATION%"=="true" set _DISP=Y
set _ANS=%_DISP%
set /p _ANS=Include CrossRef reference lookup [%_DISP%]:
call :norm_bool CROSSREF_INTEGRATION
if /i "%CROSSREF_INTEGRATION%"=="true" set /p CROSSREF_EMAIL=CrossRef contact email [%CROSSREF_EMAIL%]:
echo.

:: --- Google Books ---
set _DISP=N
if /i "%GOOGLE_BOOKS_INTEGRATION%"=="true" set _DISP=Y
set _ANS=%_DISP%
set /p _ANS=Include Google Books reference lookup [%_DISP%]:
call :norm_bool GOOGLE_BOOKS_INTEGRATION
if /i "%GOOGLE_BOOKS_INTEGRATION%"=="false" goto after_google
set /p GOOGLE_BOOKS_API_KEY=Google Books API key [%GOOGLE_BOOKS_API_KEY%]:
:after_google
echo.

:: --- Simple Manifest Server ---
set _DISP=N
if /i "%MANIFEST_SERVER_INTEGRATION%"=="true" set _DISP=Y
set _ANS=%_DISP%
set /p _ANS=Include Simple Manifest Server [%_DISP%]:
call :norm_bool MANIFEST_SERVER_INTEGRATION
if /i "%MANIFEST_SERVER_INTEGRATION%"=="false" goto after_manifest
set /p MANIFEST_SERVER_URI=Manifest Server URI [%MANIFEST_SERVER_URI%]:
:after_manifest
echo.

:: --- TEI Publisher ---
set TEIPUB_DOCKER=false
set _DISP=N
if /i "%TEIPUB_INTEGRATION%"=="true" set _DISP=Y
set _ANS=%_DISP%
set /p _ANS=Include TEI Publisher integration [%_DISP%]:
call :norm_bool TEIPUB_INTEGRATION
if /i "%TEIPUB_INTEGRATION%"=="false" goto after_teipub
set _DISP=N
if /i "%TEIPUB_DOCKER%"=="true" set _DISP=Y
set _ANS=%_DISP%
set /p _ANS=Run TEI Publisher as a Docker service [%_DISP%]:
call :norm_bool TEIPUB_DOCKER
if /i "%TEIPUB_DOCKER%"=="false" goto teipub_external
set TEIPUB_URI=http://tei-publisher:8080/exist/apps/tei-publisher
echo TEI Publisher URI set to %TEIPUB_URI%
goto after_teipub_uri
:teipub_external
set /p TEIPUB_URI=TEI Publisher URI [%TEIPUB_URI%]:
:after_teipub_uri
:after_teipub
echo.

:: --- ORCID (not available on localhost) ---
if /i "%SITE_ROOT%"=="http://localhost" goto no_orcid_prompt
set _DISP=N
if /i "%ORCID_INTEGRATION%"=="true" set _DISP=Y
set _ANS=%_DISP%
set /p _ANS=Include ORCID authentication [%_DISP%]:
call :norm_bool ORCID_INTEGRATION
if /i "%ORCID_INTEGRATION%"=="false" goto skip_orcid
set /p ORCID_CLIENT_ID=ORCID Client ID [%ORCID_CLIENT_ID%]:
set _SEC_DISP=
if not "%ORCID_CLIENT_SECRET%"=="" set _SEC_DISP=*set*
set _ANS=
set /p _ANS=ORCID Client Secret [%_SEC_DISP%]:
if not "!_ANS!"=="" set ORCID_CLIENT_SECRET=!_ANS!
set /p ORCID_OATH_ADDRESS=ORCID OAuth address [%ORCID_OATH_ADDRESS%]:
set /p ORCID_API_ADDRESS=ORCID API address [%ORCID_API_ADDRESS%]:
goto skip_orcid
:no_orcid_prompt
set ORCID_INTEGRATION=false
set ORCID_CLIENT_ID=
set ORCID_CLIENT_SECRET=
:skip_orcid
echo.

:: --- Primo ---
set _DISP=N
if /i "%PRIMO_INTEGRATION%"=="true" set _DISP=Y
set _ANS=%_DISP%
set /p _ANS=Include Primo/Alma library search [%_DISP%]:
call :norm_bool PRIMO_INTEGRATION
if /i "%PRIMO_INTEGRATION%"=="false" goto after_primo
set /p PRIMO_API_KEY=Primo API key [%PRIMO_API_KEY%]:
set /p PRIMO_VID=Primo VID [%PRIMO_VID%]:
set /p PRIMO_URI=Primo URI [%PRIMO_URI%]:
:after_primo
echo.

:: --- Annotation Publication Server ---
set _DISP=N
if /i "%PUBLICATION%"=="true" set _DISP=Y
set _ANS=%_DISP%
set /p _ANS=Enable annotation publication (requires Simple Manifest Server) [%_DISP%]:
call :norm_bool PUBLICATION
echo.

:: ============================================
:: Admin User Setup
:: ============================================
echo ============================================
echo  Admin User Setup
echo ============================================
echo.

set USERNAME=%ADMIN_EMAIL%
set /p USERNAME=Admin Email [%ADMIN_EMAIL%]:
if "%USERNAME%"=="" set USERNAME=%ADMIN_EMAIL%
if "%USERNAME%"=="" (
    echo Error: username cannot be empty
    exit /b 1
)
set ADMIN_EMAIL=%USERNAME%

for /f "usebackq delims=" %%p in (`powershell -Command "$p = Read-Host -AsSecureString 'Password'; [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"`) do set PASSWORD1=%%p
for /f "usebackq delims=" %%p in (`powershell -Command "$p = Read-Host -AsSecureString 'Repeat Password'; [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"`) do set PASSWORD2=%%p

if "%PASSWORD1%"=="" (
    echo Error: password cannot be empty
    exit /b 1
)
if not "%PASSWORD1%"=="%PASSWORD2%" (
    echo Error: passwords do not match
    exit /b 1
)
echo Passwords match

:: ============================================
:: Save config for next run
:: ============================================
(
    echo SITE_ROOT=%SITE_ROOT%
    echo REGISTRATION_ENABLED=%REGISTRATION_ENABLED%
    echo CROSSREF_INTEGRATION=%CROSSREF_INTEGRATION%
    echo CROSSREF_EMAIL=%CROSSREF_EMAIL%
    echo GOOGLE_BOOKS_INTEGRATION=%GOOGLE_BOOKS_INTEGRATION%
    echo GOOGLE_BOOKS_API_KEY=%GOOGLE_BOOKS_API_KEY%
    echo MANIFEST_SERVER_INTEGRATION=%MANIFEST_SERVER_INTEGRATION%
    echo MANIFEST_SERVER_URI=%MANIFEST_SERVER_URI%
    echo ORCID_INTEGRATION=%ORCID_INTEGRATION%
    echo ORCID_CLIENT_ID=%ORCID_CLIENT_ID%
    echo ORCID_CLIENT_SECRET=%ORCID_CLIENT_SECRET%
    echo ORCID_OATH_ADDRESS=%ORCID_OATH_ADDRESS%
    echo ORCID_API_ADDRESS=%ORCID_API_ADDRESS%
    echo PRIMO_INTEGRATION=%PRIMO_INTEGRATION%
    echo PRIMO_API_KEY=%PRIMO_API_KEY%
    echo PRIMO_VID=%PRIMO_VID%
    echo PRIMO_URI=%PRIMO_URI%
    echo PUBLICATION=%PUBLICATION%
    echo TEIPUB_INTEGRATION=%TEIPUB_INTEGRATION%
    echo TEIPUB_DOCKER=%TEIPUB_DOCKER%
    echo TEIPUB_URI=%TEIPUB_URI%
    echo MIRADOR_CLIENT=%MIRADOR_CLIENT%
    echo ADMIN_EMAIL=%ADMIN_EMAIL%
) > init.cfg
echo init.cfg saved.
echo.

:: ============================================
:: Write .env for docker compose
:: ============================================
(
    echo SITE_ROOT=%SITE_ROOT%
    echo DB_PASSWORD=%DB_PASSWORD%
    echo ENCRYPT_KEY=%ENCRYPT_KEY%
    echo REGISTRATION_ENABLED=%REGISTRATION_ENABLED%
    echo MANIFEST_SERVER_INTEGRATION=%MANIFEST_SERVER_INTEGRATION%
    echo MANIFEST_SERVER_URI=%MANIFEST_SERVER_URI%
    echo CROSSREF_INTEGRATION=%CROSSREF_INTEGRATION%
    echo CROSSREF_EMAIL=%CROSSREF_EMAIL%
    echo GOOGLE_BOOKS_INTEGRATION=%GOOGLE_BOOKS_INTEGRATION%
    echo GOOGLE_BOOKS_API_KEY=%GOOGLE_BOOKS_API_KEY%
    echo ORCID_INTEGRATION=%ORCID_INTEGRATION%
    echo ORCID_CLIENT_ID=%ORCID_CLIENT_ID%
    echo ORCID_CLIENT_SECRET=%ORCID_CLIENT_SECRET%
    echo ORCID_OATH_ADDRESS=%ORCID_OATH_ADDRESS%
    echo ORCID_API_ADDRESS=%ORCID_API_ADDRESS%
    echo PRIMO_INTEGRATION=%PRIMO_INTEGRATION%
    echo PRIMO_API_KEY=%PRIMO_API_KEY%
    echo PRIMO_VID=%PRIMO_VID%
    echo PRIMO_URI=%PRIMO_URI%
    echo PUBLICATION=%PUBLICATION%
    echo TEIPUB_INTEGRATION=%TEIPUB_INTEGRATION%
    echo TEIPUB_URI=%TEIPUB_URI%
    echo MIRADOR_CLIENT=%MIRADOR_CLIENT%
) > .env
echo .env written.
echo.

:: Write credentials file
(
    echo USERNAME=%USERNAME%
    echo PASSWORD=%PASSWORD1%
    echo PASSWORD_REPEAT=%PASSWORD2%
) > credentials.txt

:: Create db_backup directory
if not exist db_backup mkdir db_backup

:: Tear down and rebuild containers
docker compose --profile tei down --rmi all -v --remove-orphans
for /f "tokens=*" %%i in ('docker images -q 2^>nul') do docker rmi %%i
set COMPOSE_PROFILES=
if /i "%TEIPUB_DOCKER%"=="true" set COMPOSE_PROFILES=--profile tei
docker compose --verbose %COMPOSE_PROFILES% up -d

:: Wait for MySQL
set MAX_TRIES=30
set WAIT_SECONDS=5
set PHP_CONTAINER=php
set /a count=0

echo Waiting for MySQL...

:wait_loop
set /a count+=1
if %count% geq %MAX_TRIES% (
    echo Error: MySQL did not become ready after %MAX_TRIES% attempts
    del credentials.txt
    exit /b 1
)

docker exec %PHP_CONTAINER% php -r "new PDO('mysql:host=mysql;dbname=bibliotheca', 'bibliotheca_user', getenv('DB_PASSWORD'));" >nul 2>&1
if %errorlevel%==0 goto mysql_ready

echo Attempt %count%/%MAX_TRIES% - waiting %WAIT_SECONDS%s...
timeout /t %WAIT_SECONDS% /nobreak >nul
goto wait_loop

:mysql_ready
echo MySQL is ready!

docker cp credentials.txt %PHP_CONTAINER%:/var/www/src/credentials.txt
docker exec %PHP_CONTAINER% php ./src/userCredentials.php
docker exec %PHP_CONTAINER% rm ./src/credentials.txt
del credentials.txt

echo.
echo Publink is now installed
endlocal
goto :eof

:: ============================================
:: Subroutine: normalize _ANS to true/false
::   y/yes/true  -> true
::   n/no/false  -> false
::   (anything else leaves the variable unchanged)
:: Usage: set _ANS=<input>  then  call :norm_bool VARNAME
:: ============================================
:norm_bool
if /i "!_ANS!"=="y"     set %1=true
if /i "!_ANS!"=="yes"   set %1=true
if /i "!_ANS!"=="true"  set %1=true
if /i "!_ANS!"=="n"     set %1=false
if /i "!_ANS!"=="no"    set %1=false
if /i "!_ANS!"=="false" set %1=false
goto :eof
