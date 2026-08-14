param(
    [int]$Port = 21116,
    [switch]$KeepTestData
)

$ErrorActionPreference = "Stop"

$RepoRoot = Split-Path -Parent $PSScriptRoot
$BaseUrl = "http://127.0.0.1:$Port"
$TempRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("rustdesk-api-static-assets-" + [guid]::NewGuid().ToString())
$DbPath = Join-Path $TempRoot "rustdesk-api-test.sqlite3"
$OldDbEnv = $env:RUSTDESK_API_DATABASE_PATH
$Server = $null

function Invoke-TestRequest {
    param(
        [string]$Path,
        [switch]$PathAsIs
    )

    $headerFile = Join-Path $TempRoot ("headers-" + [guid]::NewGuid().ToString() + ".txt")
    $bodyFile = Join-Path $TempRoot ("body-" + [guid]::NewGuid().ToString() + ".txt")
    $args = @("-sS", "-D", $headerFile, "-o", $bodyFile, "-w", "%{http_code}")
    if ($PathAsIs) {
        $args += "--path-as-is"
    }
    $args += "$BaseUrl$Path"

    $statusText = (& curl.exe @args) -join "`n"
    if ($LASTEXITCODE -ne 0) {
        throw "curl failed for $Path"
    }

    $headers = Get-Content -LiteralPath $headerFile -Raw
    $body = Get-Content -LiteralPath $bodyFile -Raw -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $headerFile,$bodyFile -Force -ErrorAction SilentlyContinue

    return @{
        Status = [int]$statusText.Trim()
        Headers = $headers
        Body = $body
    }
}

function Invoke-PhpCli {
    param([string[]]$Arguments)

    Push-Location $RepoRoot
    try {
        $output = & php @Arguments 2>&1
        $code = $LASTEXITCODE
    } finally {
        Pop-Location
    }
    $text = ($output | ForEach-Object { $_.ToString() }) -join "`n"
    if ($code -ne 0) {
        throw "php $($Arguments -join ' ') failed with exit code $code. Output: $text"
    }
    return $text
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

function Wait-ForServer {
    for ($i = 0; $i -lt 60; $i++) {
        try {
            $health = Invoke-TestRequest -Path "/health"
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
    Invoke-PhpCli -Arguments @("scripts\migrate.php") | Out-Null

    $serverStart = New-Object System.Diagnostics.ProcessStartInfo
    $serverStart.FileName = "php"
    $serverStart.Arguments = "-S 127.0.0.1:$Port -t public public/index.php"
    $serverStart.WorkingDirectory = $RepoRoot
    $serverStart.UseShellExecute = $false
    $serverStart.CreateNoWindow = $true
    $serverStart.EnvironmentVariables["RUSTDESK_API_DATABASE_PATH"] = $DbPath
    $Server = [System.Diagnostics.Process]::Start($serverStart)
    Wait-ForServer

    $css = Invoke-TestRequest -Path "/assets/admin.css"
    Assert-Status $css 200 "GET /assets/admin.css"
    Assert-True ($css.Body.Length -gt 0) "admin.css body should not be empty"
    Assert-True ($css.Body -like "*:root*") "admin.css should contain expected CSS content"
    Assert-True ($css.Headers -match "Content-Type:\s*text/css") "admin.css should be served as text/css"

    $js = Invoke-TestRequest -Path "/assets/admin.js"
    Assert-Status $js 200 "GET /assets/admin.js"
    Assert-True ($js.Body.Length -gt 0) "admin.js body should not be empty"
    Assert-True ($js.Body -like "*querySelectorAll*") "admin.js should contain expected JavaScript content"
    Assert-True ($js.Headers -match "Content-Type:\s*(application|text)/(javascript|x-javascript)") "admin.js should be served as JavaScript"

    $admin = Invoke-TestRequest -Path "/admin"
    Assert-Status $admin 303 "GET /admin should remain dynamic"

    $loginOptions = Invoke-TestRequest -Path "/api/login-options"
    Assert-Status $loginOptions 200 "GET /api/login-options should remain dynamic"
    Assert-True ($loginOptions.Body.Trim() -eq '[""]') "login-options response should be unchanged"

    $missing = Invoke-TestRequest -Path "/assets/does-not-exist.css"
    Assert-Status $missing 404 "missing static asset"

    $traversal = Invoke-TestRequest -Path "/assets/../../data/.htaccess" -PathAsIs
    Assert-True ($traversal.Status -ne 200) "path traversal request must not return HTTP 200"
    Assert-True ($traversal.Body -notlike "*Deny from all*") "path traversal must not expose private data/.htaccess"

    $sourceFile = Invoke-TestRequest -Path "/src/Database.php"
    Assert-True ($sourceFile.Status -ne 200) "direct source file request must not return HTTP 200"
    Assert-True ($sourceFile.Body -notlike "*final class Database*") "direct source file request must not expose PHP source"

    $configFile = Invoke-TestRequest -Path "/config/config.example.php"
    Assert-True ($configFile.Status -ne 200) "direct config file request must not return HTTP 200"
    Assert-True ($configFile.Body -notlike "*database_path*") "direct config file request must not expose config source"

    Write-Host "All static asset routing tests passed."
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
