param(
    [int]$Port = 21115,
    [switch]$KeepTestData
)

$ErrorActionPreference = "Stop"

$RepoRoot = Split-Path -Parent $PSScriptRoot
$BaseUrl = "http://127.0.0.1:$Port"
$TempRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("rustdesk-api-phase4-" + [guid]::NewGuid().ToString())
$DbPath = Join-Path $TempRoot "rustdesk-api-test.sqlite3"
$CookieAdmin = Join-Path $TempRoot "admin.cookies.txt"
$CookieOtherAdmin = Join-Path $TempRoot "other-admin.cookies.txt"
$CookieNonAdmin = Join-Path $TempRoot "non-admin.cookies.txt"
$OldDbEnv = $env:RUSTDESK_API_DATABASE_PATH
$Server = $null

function UrlEncode {
    param([string]$Value)
    return [System.Uri]::EscapeDataString($Value)
}

function New-FormBody {
    param($Pairs)

    $normalized = @()
    $pending = $null
    foreach ($pair in $Pairs) {
        if ($pair -is [array] -and $pair.Count -eq 2) {
            $normalized += ,@([string]$pair[0], [string]$pair[1])
            continue
        }
        if ($pair -is [System.Collections.DictionaryEntry]) {
            $normalized += ,@([string]$pair.Key, [string]$pair.Value)
            continue
        }
        if ($pair -is [psobject] -and $pair.PSObject.Properties.Name -contains "value" -and $pair.value.Count -eq 2) {
            $normalized += ,@([string]$pair.value[0], [string]$pair.value[1])
            continue
        }
        if ($pending -eq $null) {
            $pending = [string]$pair
        } else {
            $normalized += ,@($pending, [string]$pair)
            $pending = $null
        }
    }
    if ($pending -ne $null) {
        throw "Unpaired form key: $pending"
    }

    $parts = @()
    foreach ($pair in $normalized) {
        $parts += (UrlEncode $pair[0]) + "=" + (UrlEncode $pair[1])
    }
    return ($parts -join "&")
}

function Invoke-CurlRequest {
    param(
        [string]$Method,
        [string]$Url,
        [string]$Body = $null,
        [string]$CookieJar = $null,
        [string]$ContentType = "application/x-www-form-urlencoded"
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
        $args = @("-sS", "-X", $Method, "-w", "`n%{http_code}")
        if ($CookieJar) {
            $args += @("-b", $CookieJar, "-c", $CookieJar)
        }
        if ($hasBody) {
            $args += @("-H", "Content-Type: $ContentType", "--data-binary", $bodyArg)
        }
        $args += $Url
        $raw = (& curl.exe @args) -join "`n"
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
        [switch]$ExpectFailure
    )

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

function Get-Csrf {
    param([string]$Html)
    if ($Html -match 'name="_csrf"\s+value="([^"]+)"') {
        return $Matches[1]
    }
    throw "CSRF token not found"
}

function Login-Admin {
    param([string]$Username, [string]$Password, [string]$CookieJar)
    $loginPage = Invoke-CurlRequest -Method "GET" -Url "$BaseUrl/admin/login" -CookieJar $CookieJar
    Assert-Status $loginPage 200 "admin login page"
    $csrf = Get-Csrf $loginPage.Body
    $body = New-FormBody @(
        @("_csrf", $csrf),
        @("username", $Username),
        @("password", $Password)
    )
    $login = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/admin/login" -Body $body -CookieJar $CookieJar
    Assert-Status $login 303 "admin login $Username"
    $dashboard = Invoke-CurlRequest -Method "GET" -Url "$BaseUrl/admin" -CookieJar $CookieJar
    Assert-Status $dashboard 200 "admin dashboard $Username"
    return Get-Csrf $dashboard.Body
}

function Invoke-AdminPost {
    param(
        [string]$Path,
        $Pairs,
        [string]$CookieJar = $CookieAdmin
    )

    $page = Invoke-CurlRequest -Method "GET" -Url "$BaseUrl/admin" -CookieJar $CookieJar
    Assert-Status $page 200 "refresh admin csrf"
    $token = Get-Csrf $page.Body
    $allPairs = @()
    $allPairs += ,@("_csrf", $token)
    $pending = $null
    foreach ($pair in $Pairs) {
        if ($pair -is [array] -and $pair.Count -eq 2) {
            $allPairs += ,@($pair[0], $pair[1])
        } elseif ($pair -is [psobject] -and $pair.PSObject.Properties.Name -contains "value" -and $pair.value.Count -eq 2) {
            $allPairs += ,@($pair.value[0], $pair.value[1])
        } elseif ($pending -eq $null) {
            $pending = [string]$pair
        } else {
            $allPairs += ,@($pending, [string]$pair)
            $pending = $null
        }
    }
    if ($pending -ne $null) {
        throw "Unpaired admin form key: $pending"
    }
    return Invoke-CurlRequest -Method "POST" -Url "$BaseUrl$Path" -Body (New-FormBody $allPairs) -CookieJar $CookieJar
}

function New-LoginJson {
    param([string]$Username, [string]$Password)
    return (@{
        username = $Username
        password = $Password
        id = "123456789"
        uuid = "$Username-phase4"
        autoLogin = $true
        type = "account"
        deviceInfo = @{ os = "windows"; type = "client"; name = "phase4" }
    } | ConvertTo-Json -Depth 10 -Compress)
}

function Login-Api {
    param([string]$Username, [string]$Password)
    $login = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/login" -Body (New-LoginJson $Username $Password) -ContentType "application/json"
    Assert-Status $login 200 "api login $Username"
    return [string](($login.Body | ConvertFrom-Json).access_token)
}

function Invoke-ApiRequest {
    param(
        [string]$Method,
        [string]$Path,
        [string]$Token,
        [string]$Body = $null
    )

    $bodyFile = $null
    $bodyArg = $null
    $args = @("-sS", "-X", $Method, "-w", "`n%{http_code}", "-H", "Content-Type: application/json", "-H", "Authorization: Bearer $Token")
    if ($PSBoundParameters.ContainsKey('Body')) {
        $bodyFile = [System.IO.Path]::GetTempFileName()
        $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
        [System.IO.File]::WriteAllText($bodyFile, $Body, $utf8NoBom)
        $bodyArg = "@$bodyFile"
        $args += @("--data-binary", $bodyArg)
    }
    $args += "$BaseUrl$Path"
    try {
        $raw = (& curl.exe @args) -join "`n"
    } finally {
        if ($bodyFile -and (Test-Path $bodyFile)) {
            Remove-Item -LiteralPath $bodyFile -Force
        }
    }
    if ($LASTEXITCODE -ne 0) {
        throw "curl API failed for $Method $Path"
    }
    $lastNewline = $raw.LastIndexOf("`n")
    return @{
        Status = [int]$raw.Substring($lastNewline + 1).Trim()
        Body = $raw.Substring(0, $lastNewline).Trim()
    }
}

function Wait-ForServer {
    for ($i = 0; $i -lt 60; $i++) {
        try {
            $health = Invoke-CurlRequest -Method "GET" -Url "$BaseUrl/health"
            if ($health.Status -eq 200) { return }
        } catch {
        }
        Start-Sleep -Milliseconds 250
    }
    throw "Server did not become ready at $BaseUrl"
}

try {
    New-Item -ItemType Directory -Force -Path $TempRoot | Out-Null
    $env:RUSTDESK_API_DATABASE_PATH = $DbPath
    Invoke-PhpCli -Arguments @("scripts\migrate.php") | Out-Null
    Invoke-PhpCli -Arguments @("scripts\migrate.php") | Out-Null
    Invoke-UserPasswordCommand -Arguments @("scripts\user.php", "create", "adminuser", "--admin", "--display-name=Admin User") -Password "admin-password" | Out-Null
    Invoke-UserPasswordCommand -Arguments @("scripts\user.php", "create", "normaluser") -Password "normal-password" | Out-Null
    Invoke-UserPasswordCommand -Arguments @("scripts\user.php", "create", "disabledadmin", "--admin", "--disabled") -Password "disabled-password" | Out-Null
    Invoke-UserPasswordCommand -Arguments @("scripts\user.php", "create", "otheradmin") -Password "otheradmin-password" | Out-Null

    $serverStart = New-Object System.Diagnostics.ProcessStartInfo
    $serverStart.FileName = "php"
    $serverStart.Arguments = "-S 127.0.0.1:$Port -t public public/index.php"
    $serverStart.WorkingDirectory = $RepoRoot
    $serverStart.UseShellExecute = $false
    $serverStart.CreateNoWindow = $true
    $serverStart.EnvironmentVariables["RUSTDESK_API_DATABASE_PATH"] = $DbPath
    $Server = [System.Diagnostics.Process]::Start($serverStart)
    Wait-ForServer

    $loginPage = Invoke-CurlRequest -Method "GET" -Url "$BaseUrl/admin/login" -CookieJar $CookieAdmin
    Assert-Status $loginPage 200 "login page"
    $loginCsrf = Get-Csrf $loginPage.Body
    $missingCsrf = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/admin/login" -Body (New-FormBody @(@("username", "adminuser"), @("password", "admin-password"))) -CookieJar $CookieAdmin
    Assert-Status $missingCsrf 400 "missing login csrf"
    $badLogin = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/admin/login" -Body (New-FormBody @(@("_csrf", $loginCsrf), @("username", "adminuser"), @("password", "wrong"))) -CookieJar $CookieAdmin
    Assert-Status $badLogin 401 "wrong admin password"
    $nonAdminPage = Invoke-CurlRequest -Method "GET" -Url "$BaseUrl/admin/login" -CookieJar $CookieNonAdmin
    Assert-Status $nonAdminPage 200 "non-admin login page"
    $nonAdminCsrf = Get-Csrf $nonAdminPage.Body
    $nonAdminLogin = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/admin/login" -Body (New-FormBody @(@("_csrf", $nonAdminCsrf), @("username", "normaluser"), @("password", "normal-password"))) -CookieJar $CookieNonAdmin
    Assert-Status $nonAdminLogin 401 "normal user rejected from admin"
    $disabledPage = Invoke-CurlRequest -Method "GET" -Url "$BaseUrl/admin/login" -CookieJar $CookieNonAdmin
    Assert-Status $disabledPage 200 "disabled admin login page"
    $disabledCsrf = Get-Csrf $disabledPage.Body
    $disabledAdminLogin = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/admin/login" -Body (New-FormBody @(@("_csrf", $disabledCsrf), @("username", "disabledadmin"), @("password", "disabled-password"))) -CookieJar $CookieNonAdmin
    Assert-Status $disabledAdminLogin 401 "disabled admin rejected"

    $csrf = Login-Admin -Username "adminuser" -Password "admin-password" -CookieJar $CookieAdmin

    $missingCreateCsrf = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/admin/users/create" -Body (New-FormBody @(@("username", "badcsrf"), @("password", "badcsrf-password"), @("enabled", "1"))) -CookieJar $CookieAdmin
    Assert-Status $missingCreateCsrf 400 "missing create csrf"
    $invalidCreateCsrf = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/admin/users/create" -Body (New-FormBody @(@("_csrf", "invalid"), @("username", "badcsrf"), @("password", "badcsrf-password"), @("enabled", "1"))) -CookieJar $CookieAdmin
    Assert-Status $invalidCreateCsrf 400 "invalid create csrf"
    $createA = Invoke-AdminPost -Path "/admin/users/create" -Pairs @(@("username", "webusera"), @("display_name", "Web User A"), @("password", "webusera-password"), @("enabled", "1"))
    Assert-Status $createA 303 "create webusera"
    $createB = Invoke-AdminPost -Path "/admin/users/create" -Pairs @(@("username", "webuserb"), @("display_name", "Web User B"), @("password", "webuserb-password"), @("enabled", "1"))
    Assert-Status $createB 303 "create webuserb"
    $duplicateA = Invoke-AdminPost -Path "/admin/users/create" -Pairs @(@("username", "webusera"), @("password", "another-password"), @("enabled", "1"))
    Assert-Status $duplicateA 200 "duplicate username shown as form error"
    Assert-True ($duplicateA.Body -like "*unique*") "duplicate user response should mention uniqueness"

    $ids = Invoke-PhpEval 'require "src/bootstrap.php"; $c = AppConfig::load(getcwd()); $db = new Database($c); $u = new UsersRepository($db, new PasswordHasher()); $a = $u->findByUsername("webusera"); $b = $u->findByUsername("webuserb"); $admin = $u->findByUsername("adminuser"); $other = $u->findByUsername("otheradmin"); echo $a["id"] . "," . $b["id"] . "," . $admin["id"] . "," . $other["id"];'
    $parts = $ids.Trim().Split(",")
    $userAId = $parts[0]
    $userBId = $parts[1]
    $adminId = $parts[2]
    $otherAdminId = $parts[3]

    $disableA = Invoke-AdminPost -Path "/admin/users/$userAId/disable" -Pairs @()
    Assert-Status $disableA 303 "disable user A"
    $disabledApiLogin = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/login" -Body (New-LoginJson "webusera" "webusera-password") -ContentType "application/json"
    Assert-Status $disabledApiLogin 401 "disabled user A rejected by API"
    $enableA = Invoke-AdminPost -Path "/admin/users/$userAId/enable" -Pairs @()
    Assert-Status $enableA 303 "enable user A"

    $passwordReset = Invoke-AdminPost -Path "/admin/users/$userAId/password" -Pairs @(@("password", "webusera-new-password"), @("confirm_password", "webusera-new-password"))
    Assert-Status $passwordReset 303 "password reset"
    $oldPassword = Invoke-CurlRequest -Method "POST" -Url "$BaseUrl/api/login" -Body (New-LoginJson "webusera" "webusera-password") -ContentType "application/json"
    Assert-Status $oldPassword 401 "old password rejected after reset"
    $apiTokenA = Login-Api -Username "webusera" -Password "webusera-new-password"

    $lastAdminProtection = Invoke-AdminPost -Path "/admin/users/$adminId/remove-admin" -Pairs @(@("confirm_self_lockout", "yes"))
    Assert-Status $lastAdminProtection 303 "last admin protection redirect"
    $adminStillAdmin = Invoke-PhpEval 'require "src/bootstrap.php"; $c = AppConfig::load(getcwd()); $u = (new UsersRepository(new Database($c), new PasswordHasher()))->findByUsername("adminuser"); echo $u["is_admin"];'
    Assert-True ($adminStillAdmin.Trim() -eq "1") "last enabled admin should remain admin"

    $makeAdminB = Invoke-AdminPost -Path "/admin/users/$userBId/make-admin" -Pairs @()
    Assert-Status $makeAdminB 303 "make webuserb admin"
    $removeAdminB = Invoke-AdminPost -Path "/admin/users/$userBId/remove-admin" -Pairs @()
    Assert-Status $removeAdminB 303 "remove webuserb admin"

    $makeOtherAdmin = Invoke-AdminPost -Path "/admin/users/$otherAdminId/make-admin" -Pairs @()
    Assert-Status $makeOtherAdmin 303 "make otheradmin admin"
    $otherCsrf = Login-Admin -Username "otheradmin" -Password "otheradmin-password" -CookieJar $CookieOtherAdmin
    $disableOther = Invoke-AdminPost -Path "/admin/users/$otherAdminId/disable" -Pairs @()
    Assert-Status $disableOther 303 "disable other admin"
    $otherAfterDisable = Invoke-CurlRequest -Method "GET" -Url "$BaseUrl/admin" -CookieJar $CookieOtherAdmin
    Assert-Status $otherAfterDisable 303 "disabled admin session loses access"

    $createDelete = Invoke-AdminPost -Path "/admin/users/create" -Pairs @(@("username", "deleteme"), @("password", "deleteme-password"), @("enabled", "1"))
    Assert-Status $createDelete 303 "create delete target"
    $deleteId = Invoke-PhpEval 'require "src/bootstrap.php"; $c = AppConfig::load(getcwd()); $u = (new UsersRepository(new Database($c), new PasswordHasher()))->findByUsername("deleteme"); echo $u["id"];'
    $deleteWrong = Invoke-AdminPost -Path "/admin/users/$($deleteId.Trim())/delete" -Pairs @(@("confirm_username", "wrong"))
    Assert-Status $deleteWrong 303 "wrong delete confirmation rejected via redirect"
    $deleteGood = Invoke-AdminPost -Path "/admin/users/$($deleteId.Trim())/delete" -Pairs @(@("confirm_username", "deleteme"))
    Assert-Status $deleteGood 303 "delete confirmed user"
    $deleteGone = Invoke-PhpEval 'require "src/bootstrap.php"; $c = AppConfig::load(getcwd()); $u = (new UsersRepository(new Database($c), new PasswordHasher()))->findByUsername("deleteme"); echo $u === null ? "gone" : "present";'
    Assert-True ($deleteGone.Trim() -eq "gone") "deleted user should be gone"

    $settingsPost = Invoke-AdminPost -Path "/admin/settings" -Pairs @(@("token_lifetime_days", "120"), @("login_max_failures", "9"), @("login_window_seconds", "700"), @("admin_session_idle_seconds", "1800"), @("admin_session_absolute_seconds", "43200"))
    Assert-Status $settingsPost 303 "settings update"
    $settingsValue = Invoke-PhpEval 'require "src/bootstrap.php"; $c = AppConfig::load(getcwd()); echo (new Settings(new Database($c)))->get("token_lifetime_days", "");'
    Assert-True ($settingsValue.Trim() -eq "120") "settings should persist"

    $tagCreate = Invoke-AdminPost -Path "/admin/users/$userAId/address-book/tag/create" -Pairs @(@("name", "WebTag"), @("color_value", "4286611584"))
    Assert-Status $tagCreate 303 "create tag"
    $tagId = Invoke-PhpEval 'require "src/bootstrap.php"; $c = AppConfig::load(getcwd()); $db = new Database($c); $u = (new UsersRepository($db, new PasswordHasher()))->findByUsername("webusera"); $stmt = $db->pdo()->prepare("SELECT id FROM address_book_tags WHERE user_id = :user_id AND name = :name"); $stmt->execute(array(":user_id" => $u["id"], ":name" => "WebTag")); echo $stmt->fetchColumn();'
    $peerCreate = Invoke-AdminPost -Path "/admin/users/$userAId/address-book/peer/create" -Pairs @(@("rustdesk_id", "888888888"), @("alias", "Web Added Peer"), @("hostname", "web-host"), @("username", "webusera"), @("platform", "windows"), @("tag_ids[]", $tagId.Trim()))
    Assert-Status $peerCreate 303 "create peer"
    $bPeerCount = Invoke-PhpEval 'require "src/bootstrap.php"; $c = AppConfig::load(getcwd()); $db = new Database($c); $u = (new UsersRepository($db, new PasswordHasher()))->findByUsername("webuserb"); $stmt = $db->pdo()->prepare("SELECT COUNT(*) FROM address_book_entries WHERE user_id = :user_id"); $stmt->execute(array(":user_id" => $u["id"])); echo $stmt->fetchColumn();'
    Assert-True ($bPeerCount.Trim() -eq "0") "admin edit for user A must not affect user B"

    $secretBook = @{
        tags = @("Secret")
        peers = @(
            @{
                id = "999999999"
                username = "webusera"
                hostname = "secret-host"
                platform = "windows"
                alias = "Secret Alias"
                tags = @("Secret")
                hash = "TEST_SECRET_OPAQUE_VALUE"
            }
        )
        tag_colors = '{"Secret":4283215696}'
    }
    $inner = ($secretBook | ConvertTo-Json -Depth 20 -Compress)
    $outer = (@{ data = $inner } | ConvertTo-Json -Depth 10 -Compress)
    $saveSecret = Invoke-ApiRequest -Method "POST" -Path "/api/ab" -Token $apiTokenA -Body $outer
    Assert-Status $saveSecret 200 "api save secret hash book"
    Assert-True ($saveSecret.Body.Length -eq 0) "api POST /api/ab must keep zero-length body"

    $bookPage = Invoke-CurlRequest -Method "GET" -Url "$BaseUrl/admin/users/$userAId/address-book" -CookieJar $CookieAdmin
    Assert-Status $bookPage 200 "address book page"
    Assert-True ($bookPage.Body -notlike "*TEST_SECRET_OPAQUE_VALUE*") "admin UI must not expose peer_hash"

    $idsForSecret = Invoke-PhpEval 'require "src/bootstrap.php"; $c = AppConfig::load(getcwd()); $db = new Database($c); $u = (new UsersRepository($db, new PasswordHasher()))->findByUsername("webusera"); $stmt = $db->pdo()->prepare("SELECT e.id AS entry_id, t.id AS tag_id FROM address_book_entries e JOIN address_book_tags t ON t.user_id = e.user_id WHERE e.user_id = :user_id AND e.rustdesk_id = :rid AND t.name = :tag"); $stmt->execute(array(":user_id" => $u["id"], ":rid" => "999999999", ":tag" => "Secret")); $row = $stmt->fetch(); echo $row["entry_id"] . "," . $row["tag_id"];'
    $secretParts = $idsForSecret.Trim().Split(",")
    $secretEntryId = $secretParts[0]
    $secretTagId = $secretParts[1]

    $tagRename = Invoke-AdminPost -Path "/admin/users/$userAId/address-book/tag/$secretTagId/rename" -Pairs @(@("name", "SecretRenamed"), @("color_value", "4283215696"))
    Assert-Status $tagRename 303 "rename tag"
    $peerEdit = Invoke-AdminPost -Path "/admin/users/$userAId/address-book/peer/$secretEntryId/update" -Pairs @(@("alias", "Alias Edited In Admin"), @("hostname", "secret-host"), @("username", "webusera"), @("platform", "windows"), @("tag_ids[]", $secretTagId))
    Assert-Status $peerEdit 303 "edit peer alias"

    $apiBook = Invoke-ApiRequest -Method "GET" -Path "/api/ab" -Token $apiTokenA
    Assert-Status $apiBook 200 "api get after admin edit"
    $apiEnvelope = $apiBook.Body | ConvertFrom-Json
    $apiInner = $apiEnvelope.data | ConvertFrom-Json
    Assert-True ($apiInner.peers[0].hash -eq "TEST_SECRET_OPAQUE_VALUE") "peer hash must survive admin alias edit"
    Assert-True ($apiInner.peers[0].alias -eq "Alias Edited In Admin") "admin alias edit should reach RustDesk API"
    Assert-True ($apiInner.tags[0] -eq "SecretRenamed") "admin tag rename should reach RustDesk API"

    $tagDelete = Invoke-AdminPost -Path "/admin/users/$userAId/address-book/tag/$secretTagId/delete" -Pairs @()
    Assert-Status $tagDelete 303 "delete tag"
    $afterTagDelete = Invoke-ApiRequest -Method "GET" -Path "/api/ab" -Token $apiTokenA
    Assert-Status $afterTagDelete 200 "api get after tag delete"
    $afterTagEnvelope = $afterTagDelete.Body | ConvertFrom-Json
    $afterTagInner = $afterTagEnvelope.data | ConvertFrom-Json
    Assert-True ($afterTagInner.peers[0].hash -eq "TEST_SECRET_OPAQUE_VALUE") "peer hash must survive tag delete"

    $peerDelete = Invoke-AdminPost -Path "/admin/users/$userAId/address-book/peer/$secretEntryId/delete" -Pairs @()
    Assert-Status $peerDelete 303 "delete peer"
    $afterPeerDelete = Invoke-ApiRequest -Method "GET" -Path "/api/ab" -Token $apiTokenA
    Assert-Status $afterPeerDelete 200 "api get after peer delete"
    Assert-True ($afterPeerDelete.Body -notlike '*999999999*') "deleted peer should disappear from RustDesk API"

    $logout = Invoke-AdminPost -Path "/admin/logout" -Pairs @()
    Assert-Status $logout 303 "admin logout"
    $afterLogout = Invoke-CurlRequest -Method "GET" -Url "$BaseUrl/admin" -CookieJar $CookieAdmin
    Assert-Status $afterLogout 303 "admin session gone after logout"

    Write-Host "All Phase 4 curl tests passed."
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
