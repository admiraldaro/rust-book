param(
    [int]$Port = 21114,
    [switch]$KeepTestData
)

$ErrorActionPreference = "Stop"

$RepoRoot = Split-Path -Parent $PSScriptRoot
$BaseUrl = "http://127.0.0.1:$Port"
$TempRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("rustdesk-api-phase3-" + [guid]::NewGuid().ToString())
$DbPath = Join-Path $TempRoot "rustdesk-api-test.sqlite3"
$OldDbEnv = $env:RUSTDESK_API_DATABASE_PATH
$Server = $null

function Invoke-CurlRequest {
    param(
        [string]$Method,
        [string]$Url,
        [string]$Body = $null,
        [string]$Token = $null
    )

    $hasBody = $PSBoundParameters.ContainsKey('Body')
    $bodyFile = $null
    $bodyArg = $null

    if ($hasBody) {
        $bodyFile = [System.IO.Path]::GetTempFileName()
        $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
        [System.IO.File]::WriteAllText($bodyFile, $Body, $utf8NoBom)
        $bodyArg = "@$bodyFile"
    }

    try {
        if ($Token -and $hasBody) {
            $raw = (& curl.exe -sS -X $Method -w "`n%{http_code}" -H "Content-Type: application/json" -H "Authorization: Bearer $Token" --data-binary $bodyArg $Url) -join "`n"
        } elseif ($Token) {
            $raw = (& curl.exe -sS -X $Method -w "`n%{http_code}" -H "Content-Type: application/json" -H "Authorization: Bearer $Token" $Url) -join "`n"
        } elseif ($hasBody) {
            $raw = (& curl.exe -sS -X $Method -w "`n%{http_code}" -H "Content-Type: application/json" --data-binary $bodyArg $Url) -join "`n"
        } else {
            $raw = (& curl.exe -sS -X $Method -w "`n%{http_code}" -H "Content-Type: application/json" $Url) -join "`n"
        }
    } finally {
        if ($bodyFile -and (Test-Path $bodyFile)) {
            Remove-Item -LiteralPath $bodyFile -Force
        }
    }

    if ($LASTEXITCODE -ne 0) {
        throw "curl failed for $Method $Url"
    }

    $lastNewline = $raw.LastIndexOf("`n")
    if ($lastNewline -lt 0) {
        throw "curl response did not contain an HTTP status code"
    }

    return @{
        Status = [int]$raw.Substring($lastNewline + 1).Trim()
        Body = $raw.Substring(0, $lastNewline).Trim()
    }
}

function Invoke-PhpCli {
    param(
        [string[]]$Arguments,
        [string]$InputText = $null,
        [switch]$ExpectFailure
    )

    if ($null -ne $InputText) {
        $psi = New-Object System.Diagnostics.ProcessStartInfo
        $psi.FileName = "php"
        $psi.Arguments = (($Arguments | ForEach-Object { '"' + ($_.Replace('"', '\"')) + '"' }) -join ' ')
        $psi.WorkingDirectory = $RepoRoot
        $psi.UseShellExecute = $false
        $psi.RedirectStandardInput = $true
        $psi.RedirectStandardOutput = $true
        $psi.RedirectStandardError = $true
        $psi.EnvironmentVariables["RUSTDESK_API_DATABASE_PATH"] = $DbPath
        $process = [System.Diagnostics.Process]::Start($psi)
        $process.StandardInput.Write($InputText)
        $process.StandardInput.Close()
        $stdout = $process.StandardOutput.ReadToEnd()
        $stderr = $process.StandardError.ReadToEnd()
        $process.WaitForExit()
        $code = $process.ExitCode
        $text = ($stdout + $stderr).Trim()
    } else {
        Push-Location $RepoRoot
        $oldErrorActionPreference = $ErrorActionPreference
        $ErrorActionPreference = "Continue"
        try {
            $output = & php @Arguments 2>&1
            $code = $LASTEXITCODE
        } finally {
            $ErrorActionPreference = $oldErrorActionPreference
            Pop-Location
        }
        $text = ($output | ForEach-Object { $_.ToString() }) -join "`n"
    }
    if ($ExpectFailure) {
        if ($code -eq 0) {
            throw "Expected php $($Arguments -join ' ') to fail, but it succeeded. Output: $text"
        }
    } elseif ($code -ne 0) {
        throw "php $($Arguments -join ' ') failed with exit code $code. Output: $text"
    }

    return $text
}

function Invoke-PhpEval {
    param([string]$Code)

    $tempPhp = [System.IO.Path]::GetTempFileName() + ".php"
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($tempPhp, "<?php`n" + $Code + "`n", $utf8NoBom)

    Push-Location $RepoRoot
    $oldErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        $output = & php $tempPhp 2>&1
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $oldErrorActionPreference
        Pop-Location
        Remove-Item -LiteralPath $tempPhp -Force -ErrorAction SilentlyContinue
    }

    $text = ($output | ForEach-Object { $_.ToString() }) -join "`n"
    if ($code -ne 0) {
        throw "php eval failed with exit code $code. Output: $text"
    }

    return $text
}

function Invoke-UserPasswordCommand {
    param(
        [string[]]$Arguments,
        [string]$Password,
        [switch]$ExpectFailure
    )

    $passwordFile = Join-Path $TempRoot ("password-" + [guid]::NewGuid().ToString() + ".txt")
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($passwordFile, $Password + "`n", $utf8NoBom)
    try {
        return Invoke-PhpCli -Arguments ($Arguments + @("--password-file=$passwordFile")) -ExpectFailure:$ExpectFailure
    } finally {
        Remove-Item -LiteralPath $passwordFile -Force -ErrorAction SilentlyContinue
    }
}

function Assert-Status {
    param($Response, [int]$Expected, [string]$Label)

    if ($Response.Status -ne $Expected) {
        throw "$Label expected HTTP $Expected, got HTTP $($Response.Status). Body: $($Response.Body)"
    }
}

function Assert-True {
    param([bool]$Condition, [string]$Message)

    if (-not $Condition) {
        throw $Message
    }
}

function ConvertTo-CompactJson {
    param($Value)
    return ($Value | ConvertTo-Json -Depth 30 -Compress)
}

function New-LoginBody {
    param([string]$Username, [string]$Password, [string]$DeviceName)

    return ConvertTo-CompactJson @{
        username = $Username
        password = $Password
        id = "123456789"
        uuid = "$Username-curl-test"
        autoLogin = $true
        type = "account"
        deviceInfo = @{
            os = "windows"
            type = "client"
            name = $DeviceName
        }
    }
}

function Login-TestAccount {
    param([string]$Username, [string]$Password, [string]$DeviceName)

    $login = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/login" -Body (New-LoginBody -Username $Username -Password $Password -DeviceName $DeviceName)
    Assert-Status $login 200 "login $Username"
    $loginJson = $login.Body | ConvertFrom-Json
    Assert-True ($loginJson.type -eq "access_token") "login $Username should contain type=access_token"
    Assert-True ([string]::IsNullOrWhiteSpace($loginJson.access_token) -eq $false) "login $Username should contain an access token"
    Assert-True ($loginJson.user.name -eq $Username) "login $Username should return that user"
    return [string]$loginJson.access_token
}

function Get-AddressBookEnvelope {
    param([string]$Token)

    $response = Invoke-CurlRequest -Method "GET" -Url "$BaseUrl/api/ab" -Token $Token
    Assert-Status $response 200 "GET /api/ab"
    $envelope = $response.Body | ConvertFrom-Json
    Assert-True ([string]::IsNullOrWhiteSpace($envelope.data) -eq $false) "address book data should be a JSON string"
    return $envelope
}

function Get-AddressBook {
    param([string]$Token)

    $envelope = Get-AddressBookEnvelope -Token $Token
    return ($envelope.data | ConvertFrom-Json)
}

function Save-AddressBook {
    param([string]$Token, $Book, [string]$Label)

    $innerJson = ConvertTo-CompactJson $Book
    $outerJson = ConvertTo-CompactJson @{ data = $innerJson }
    $response = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/ab" -Body $outerJson -Token $Token
    Assert-Status $response 200 $Label
    Assert-True ($response.Body.Length -eq 0) "$Label should return HTTP 200 with an empty body"
    Assert-True ($response.Body -ne "[]") "$Label must not return JSON []"
}

function Assert-PeerId {
    param($Book, [string]$Id, [int]$ExpectedCount, [string]$Message)

    Assert-True (@($Book.peers | Where-Object { $_.id -eq $Id }).Count -eq $ExpectedCount) $Message
}

function Wait-ForServer {
    for ($i = 0; $i -lt 60; $i++) {
        try {
            $health = Invoke-CurlRequest -Method "GET" -Url "$BaseUrl/health"
            if ($health.Status -eq 200) {
                return
            }
        } catch {
        }
        Start-Sleep -Milliseconds 250
    }

    throw "Server did not become ready at $BaseUrl"
}

try {
    New-Item -ItemType Directory -Force -Path $TempRoot | Out-Null
    $env:RUSTDESK_API_DATABASE_PATH = $DbPath

    Write-Host "Preparing temporary Phase 3 database at $DbPath"
    Invoke-PhpCli -Arguments @("scripts\migrate.php") | Out-Null
    Invoke-PhpCli -Arguments @("scripts\migrate.php") | Out-Null

    Invoke-UserPasswordCommand -Arguments @("scripts\user.php", "create", "phase3a", "--admin", "--display-name=Phase 3 A") -Password "phase3a-password" | Out-Null
    Invoke-UserPasswordCommand -Arguments @("scripts\user.php", "create", "phase3b", "--display-name=Phase 3 B") -Password "phase3b-password" | Out-Null
    Invoke-UserPasswordCommand -Arguments @("scripts\user.php", "create", "phase3disabled", "--disabled") -Password "disabled-password" | Out-Null
    Invoke-UserPasswordCommand -Arguments @("scripts\user.php", "create", "tempdelete") -Password "delete-password" | Out-Null
    Invoke-UserPasswordCommand -Arguments @("scripts\user.php", "create", "phase2legacy") -Password "legacy-password" | Out-Null
    Invoke-UserPasswordCommand -Arguments @("scripts\user.php", "create", "phase3a") -Password "duplicate-password" -ExpectFailure | Out-Null

    $foreignKeys = Invoke-PhpEval 'require "src/bootstrap.php"; $c = AppConfig::load(getcwd()); $db = new Database($c); echo $db->pdo()->query("PRAGMA foreign_keys")->fetchColumn();'
    Assert-True ($foreignKeys.Trim() -eq "1") "SQLite foreign_keys PRAGMA should be enabled"

    $Phase2SourceDir = Join-Path $TempRoot "phase2-address-books"
    New-Item -ItemType Directory -Force -Path $Phase2SourceDir | Out-Null
    $legacyBook = @{
        tags = @("Legacy")
        peers = @(
            @{
                id = "777777777"
                username = "phase2legacy"
                hostname = "phase2-legacy"
                platform = "windows"
                alias = "Phase 2 Legacy Imported"
                tags = @("Legacy")
                hash = "LEGACYHASH=="
            }
        )
        tag_colors = '{"Legacy":4286611584}'
    }
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText((Join-Path $Phase2SourceDir "phase2legacy.json"), (ConvertTo-CompactJson $legacyBook), $utf8NoBom)
    [System.IO.File]::WriteAllText((Join-Path $Phase2SourceDir "nouser.json"), (ConvertTo-CompactJson $legacyBook), $utf8NoBom)

    $dryRunOutput = Invoke-PhpCli -Arguments @("scripts\migrate-phase2-data.php", "--dry-run", "--source-dir=$Phase2SourceDir")
    Assert-True ($dryRunOutput -like "*WOULD IMPORT phase2legacy.json -> phase2legacy*") "Phase 2 migration dry-run should report matching existing users"
    Assert-True ($dryRunOutput -like "*SKIP nouser.json*") "Phase 2 migration should skip files without matching DB users"
    $afterDryRunCount = Invoke-PhpEval 'require "src/bootstrap.php"; $c = AppConfig::load(getcwd()); $db = new Database($c); $pdo = $db->pdo(); $stmt = $pdo->query("SELECT COUNT(*) FROM address_book_entries"); echo $stmt->fetchColumn();'
    Assert-True ($afterDryRunCount.Trim() -eq "0") "Phase 2 migration dry-run must not alter address-book tables"
    $importOutput = Invoke-PhpCli -Arguments @("scripts\migrate-phase2-data.php", "--yes", "--source-dir=$Phase2SourceDir")
    Assert-True ($importOutput -like "*Imported 1 Phase 2 address book*") "Phase 2 migration should import exactly one matching book"
    $legacyImported = Invoke-PhpEval 'require "src/bootstrap.php"; $c = AppConfig::load(getcwd()); $db = new Database($c); $users = new UsersRepository($db, new PasswordHasher()); $u = $users->findByUsername("phase2legacy"); $book = (new AddressBookRepository($db))->getForUser((int) $u["id"]); echo count($book["peers"]) . "," . $book["peers"][0]["hash"] . "," . $book["tag_colors"];'
    Assert-True ($legacyImported.Trim() -eq '1,LEGACYHASH==,{"Legacy":4286611584}') "Phase 2 migration should preserve peer hash and tag color literals"

    Write-Host "Starting PHP built-in server at $BaseUrl"
    $serverStart = New-Object System.Diagnostics.ProcessStartInfo
    $serverStart.FileName = "php"
    $serverStart.Arguments = "-S 127.0.0.1:$Port -t public public/index.php"
    $serverStart.WorkingDirectory = $RepoRoot
    $serverStart.UseShellExecute = $false
    $serverStart.CreateNoWindow = $true
    $serverStart.EnvironmentVariables["RUSTDESK_API_DATABASE_PATH"] = $DbPath
    $Server = [System.Diagnostics.Process]::Start($serverStart)
    Wait-ForServer

    $health = Invoke-CurlRequest -Method "GET" -Url "$BaseUrl/health"
    Assert-Status $health 200 "health"
    $healthJson = $health.Body | ConvertFrom-Json
    Assert-True ($healthJson.phase -eq "4") "health should report the current public phase"
    Assert-True ($healthJson.database_initialized -eq $true) "health should report initialized database"

    $options = Invoke-CurlRequest -Method "GET" -Url "$BaseUrl/api/login-options"
    Assert-Status $options 200 "login-options"
    $optionsJson = $options.Body | ConvertFrom-Json
    Assert-True ($optionsJson.Count -eq 1) "login-options should contain one password-login marker"

    $missingTokenCurrentUser = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/currentUser" -Body '{}'
    Assert-Status $missingTokenCurrentUser 401 "currentUser without Authorization"

    $invalidTokenCurrentUser = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/currentUser" -Body '{}' -Token "not-a-real-token"
    Assert-Status $invalidTokenCurrentUser 401 "currentUser with invalid token"

    $personalWithoutToken = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/ab/personal"
    Assert-Status $personalWithoutToken 404 "ab personal legacy fallback without Authorization"
    Assert-True ($personalWithoutToken.Body.Length -eq 0) "POST /api/ab/personal should return an empty 404 body without Authorization"

    $badLogin = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/login" -Body (New-LoginBody -Username "phase3a" -Password "wrong" -DeviceName "bad-login")
    Assert-Status $badLogin 401 "wrong password"

    $disabledLogin = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/login" -Body (New-LoginBody -Username "phase3disabled" -Password "disabled-password" -DeviceName "disabled-login")
    Assert-Status $disabledLogin 401 "disabled account login"

    $tokenA = Login-TestAccount -Username "phase3a" -Password "phase3a-password" -DeviceName "curl-phase3a"
    $tokenB = Login-TestAccount -Username "phase3b" -Password "phase3b-password" -DeviceName "curl-phase3b"
    $tokenA2 = Login-TestAccount -Username "phase3a" -Password "phase3a-password" -DeviceName "curl-phase3a-second"
    Assert-True ($tokenA -ne $tokenB) "phase3a and phase3b tokens should differ"
    Assert-True ($tokenA -ne $tokenA2) "two phase3a logins should receive independent tokens"

    $tokenCheck = Invoke-PhpEval ('require "src/bootstrap.php"; $c = AppConfig::load(getcwd()); $db = new Database($c); $pdo = $db->pdo(); $token = "' + $tokenA + '"; $hash = hash("sha256", $token); $stmt = $pdo->prepare("SELECT COUNT(*) FROM api_tokens WHERE token_hash = :raw"); $stmt->execute(array(":raw" => $token)); $rawCount = $stmt->fetchColumn(); $stmt = $pdo->prepare("SELECT COUNT(*) FROM api_tokens WHERE token_hash = :hash AND LENGTH(token_hash) = 64"); $stmt->execute(array(":hash" => $hash)); echo $rawCount . "," . $stmt->fetchColumn();')
    Assert-True ($tokenCheck.Trim() -eq "0,1") "API token table should store only SHA-256 token hashes"

    $currentUserA = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/currentUser" -Body '{"id":"123456789","uuid":"curl-test-a"}' -Token $tokenA
    Assert-Status $currentUserA 200 "currentUser phase3a"
    Assert-True (($currentUserA.Body | ConvertFrom-Json).name -eq "phase3a") "currentUser should return phase3a"

    $currentUserB = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/currentUser" -Body '{"id":"123456789","uuid":"curl-test-b"}' -Token $tokenB
    Assert-Status $currentUserB 200 "currentUser phase3b"
    Assert-True (($currentUserB.Body | ConvertFrom-Json).name -eq "phase3b") "currentUser should return phase3b"

    $personal = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/ab/personal" -Token $tokenA
    Assert-Status $personal 404 "ab personal legacy fallback"
    Assert-True ($personal.Body.Length -eq 0) "POST /api/ab/personal should keep an empty 404 body"

    $initialA = Get-AddressBook -Token $tokenA
    Assert-True (@($initialA.tags).Count -eq 0) "phase3a initial tags should be empty"
    Assert-True (@($initialA.peers).Count -eq 0) "phase3a initial peers should be empty"
    Assert-True ($initialA.tag_colors -eq "{}") "phase3a initial tag_colors should be {}"

    $modifiedA = @{
        tags = @("Edited A", "Home")
        peers = @(
            @{
                id = "111111111"
                username = "phase3a"
                hostname = "phase3-desktop"
                platform = "windows"
                alias = "Phase 3 Desktop"
                tags = @("Edited A", "Home")
                hash = "AQIDBAU="
            },
            @{
                id = "222222222"
                username = "phase3a"
                hostname = "phase3-laptop"
                platform = "windows"
                alias = "Phase 3 Laptop"
                tags = @("Home")
                hash = ""
            },
            @{
                id = "333333333"
                username = "phase3a"
                hostname = "phase3-server"
                platform = "linux"
                alias = "Phase 3 Server"
                tags = @("Edited A")
                hash = "CgsMDQ4="
            }
        )
        tag_colors = '{"Edited A":4286611584,"Home":4283215696}'
    }

    Save-AddressBook -Token $tokenA -Book $modifiedA -Label "save modified phase3a book"
    $savedAEnvelope = Get-AddressBookEnvelope -Token $tokenA
    Assert-True ($savedAEnvelope.data.StartsWith("{")) "GET /api/ab data should be a JSON-encoded string"
    $savedA = $savedAEnvelope.data | ConvertFrom-Json
    Assert-PeerId $savedA "111111111" 1 "phase3a Desktop ID should be present"
    Assert-PeerId $savedA "222222222" 1 "phase3a Laptop ID should be present"
    Assert-PeerId $savedA "333333333" 1 "phase3a Server ID should be present"
    Assert-True ((@($savedA.peers | Where-Object { $_.id -eq "111111111" })[0].hash) -eq "AQIDBAU=") "phase3a peer hash should be preserved"
    Assert-True ($savedA.tag_colors -eq '{"Edited A":4286611584,"Home":4283215696}') "tag_colors should remain JSON numeric literals, including >32-bit color values"

    $savedAFromSecondToken = Get-AddressBook -Token $tokenA2
    Assert-PeerId $savedAFromSecondToken "333333333" 1 "second phase3a token should see saved phase3a changes"

    $initialB = Get-AddressBook -Token $tokenB
    Assert-PeerId $initialB "111111111" 0 "phase3b should not see phase3a peers"

    $modifiedB = @{
        tags = @("Edited B", "Personal")
        peers = @(
            @{
                id = "444444444"
                username = "phase3b"
                hostname = "phase3-personal-pc"
                platform = "windows"
                alias = "Phase 3B Personal PC"
                tags = @("Edited B")
                hash = "FBUWFw=="
            }
        )
        tag_colors = '{"Edited B":4283215696}'
    }

    Save-AddressBook -Token $tokenB -Book $modifiedB -Label "save modified phase3b book"
    $savedB = Get-AddressBook -Token $tokenB
    Assert-PeerId $savedB "444444444" 1 "phase3b peer should persist"
    Assert-PeerId $savedB "111111111" 0 "phase3b should remain isolated"
    Assert-True ((@($savedB.peers | Where-Object { $_.id -eq "444444444" })[0].hash) -eq "FBUWFw==") "phase3b peer hash should be preserved"

    $stillA = Get-AddressBook -Token $tokenA
    Assert-PeerId $stillA "333333333" 1 "phase3a should keep its own peers"
    Assert-PeerId $stillA "444444444" 0 "phase3a should not receive phase3b peer"

    $abGetA = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/ab/get" -Body '{}' -Token $tokenA
    Assert-Status $abGetA 200 "legacy ab get alias phase3a"
    $abGetAJson = $abGetA.Body | ConvertFrom-Json
    Assert-True ((($abGetAJson.data | ConvertFrom-Json).peers).Count -eq 3) "POST /api/ab/get should return phase3a book"

    $beforeInvalidEnvelope = Get-AddressBookEnvelope -Token $tokenA
    $invalidPayload = ConvertTo-CompactJson @{ data = '{"tags":"bad","peers":[],"tag_colors":"{}"}' }
    $invalidResponse = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/ab" -Body $invalidPayload -Token $tokenA
    Assert-Status $invalidResponse 400 "invalid legacy address book structure"
    $malformedPayload = ConvertTo-CompactJson @{ data = '{"tags":["bad"],"peers":[' }
    $malformedResponse = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/ab" -Body $malformedPayload -Token $tokenA
    Assert-Status $malformedResponse 400 "malformed inner legacy address book JSON"
    $afterInvalidEnvelope = Get-AddressBookEnvelope -Token $tokenA
    Assert-True ($beforeInvalidEnvelope.data -eq $afterInvalidEnvelope.data) "invalid payload must not alter the saved phase3a book"

    $invalidGet = Invoke-CurlRequest -Method "GET" -Url "$BaseUrl/api/ab" -Token "not-a-real-token"
    Assert-Status $invalidGet 401 "invalid token GET /api/ab"

    $groups = Invoke-CurlRequest -Method "GET" -Url "$BaseUrl/api/device-group/accessible?current=1&pageSize=100" -Token $tokenA
    Assert-Status $groups 200 "device group stub"
    $usersStub = Invoke-CurlRequest -Method "GET" -Url "$BaseUrl/api/users?current=1&pageSize=100&accessible=&status=1" -Token $tokenA
    Assert-Status $usersStub 200 "users stub"
    $peers = Invoke-CurlRequest -Method "GET" -Url "$BaseUrl/api/peers?current=1&pageSize=100&accessible=&status=1" -Token $tokenA
    Assert-Status $peers 200 "peers stub"
    $heartbeat = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/heartbeat" -Body '{"id":"123456789","uuid":"curl-test","ver":143456,"modified_at":0}'
    Assert-Status $heartbeat 200 "heartbeat"
    $sysinfoVer = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/sysinfo_ver"
    Assert-Status $sysinfoVer 200 "sysinfo_ver"
    $sysinfo = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/sysinfo" -Body '{"id":"123456789","uuid":"curl-test","version":"phase3"}'
    Assert-Status $sysinfo 200 "sysinfo"
    Assert-True ($sysinfo.Body -eq "ID_NOT_FOUND") "sysinfo should return ID_NOT_FOUND"

    $logoutA = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/logout" -Body '{"id":"123456789","uuid":"curl-test-a"}' -Token $tokenA
    Assert-Status $logoutA 200 "logout phase3a"
    $afterLogoutA = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/currentUser" -Body '{"id":"123456789","uuid":"curl-test-a"}' -Token $tokenA
    Assert-Status $afterLogoutA 401 "currentUser after logout phase3a"

    Invoke-PhpCli -Arguments @("scripts\user.php", "enable", "phase3disabled") | Out-Null
    $disabledToken = Login-TestAccount -Username "phase3disabled" -Password "disabled-password" -DeviceName "disabled-after-enable"
    Invoke-PhpCli -Arguments @("scripts\user.php", "disable", "phase3disabled") | Out-Null
    $disabledCurrent = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/currentUser" -Body '{}' -Token $disabledToken
    Assert-Status $disabledCurrent 401 "disabled account token should stop working"

    $tokenBeforePasswd = Login-TestAccount -Username "phase3a" -Password "phase3a-password" -DeviceName "before-passwd"
    Invoke-UserPasswordCommand -Arguments @("scripts\user.php", "passwd", "phase3a") -Password "phase3a-new-password" | Out-Null
    $revokedByPasswd = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/currentUser" -Body '{}' -Token $tokenBeforePasswd
    Assert-Status $revokedByPasswd 401 "password change should revoke existing tokens"
    $oldPassword = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/login" -Body (New-LoginBody -Username "phase3a" -Password "phase3a-password" -DeviceName "old-password")
    Assert-Status $oldPassword 401 "old password should stop working"
    $newPasswordToken = Login-TestAccount -Username "phase3a" -Password "phase3a-new-password" -DeviceName "new-password"

    $expireToken = Login-TestAccount -Username "phase3a" -Password "phase3a-new-password" -DeviceName "expire-token"
    Invoke-PhpEval ('require "src/bootstrap.php"; $c = AppConfig::load(getcwd()); $db = new Database($c); $pdo = $db->pdo(); $hash = hash("sha256", "' + $expireToken + '"); $stmt = $pdo->prepare("UPDATE api_tokens SET expires_at = :past WHERE token_hash = :hash"); $stmt->execute(array(":past" => "2000-01-01T00:00:00Z", ":hash" => $hash));') | Out-Null
    $expiredCurrent = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/currentUser" -Body '{}' -Token $expireToken
    Assert-Status $expiredCurrent 401 "expired token should be rejected"

    $deleteToken = Login-TestAccount -Username "tempdelete" -Password "delete-password" -DeviceName "delete-before"
    Invoke-PhpCli -Arguments @("scripts\user.php", "delete", "tempdelete", "--yes") | Out-Null
    $deletedCurrent = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/currentUser" -Body '{}' -Token $deleteToken
    Assert-Status $deletedCurrent 401 "deleted account token should be rejected"
    $deletedLogin = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/login" -Body (New-LoginBody -Username "tempdelete" -Password "delete-password" -DeviceName "deleted-login")
    Assert-Status $deletedLogin 401 "deleted account login should fail"

    Invoke-PhpCli -Arguments @("scripts\user.php", "remove-admin", "phase3a") -ExpectFailure | Out-Null

    $finalCurrent = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/currentUser" -Body '{}' -Token $newPasswordToken
    Assert-Status $finalCurrent 200 "new password token remains valid"

    Write-Host "All Phase 3 curl tests passed."
} finally {
    if ($Server -and -not $Server.HasExited) {
        Stop-Process -Id $Server.Id -Force
    }

    if ($OldDbEnv -eq $null) {
        Remove-Item Env:RUSTDESK_API_DATABASE_PATH -ErrorAction SilentlyContinue
    } else {
        $env:RUSTDESK_API_DATABASE_PATH = $OldDbEnv
    }

    if (-not $KeepTestData -and (Test-Path $TempRoot)) {
        $tempBase = [System.IO.Path]::GetTempPath()
        $fullTemp = [System.IO.Path]::GetFullPath($TempRoot)
        if ($fullTemp.StartsWith($tempBase, [System.StringComparison]::OrdinalIgnoreCase)) {
            Remove-Item -LiteralPath $TempRoot -Recurse -Force
        }
    }
}
