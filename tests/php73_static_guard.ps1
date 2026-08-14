$ErrorActionPreference = "Stop"

$phpFiles = Get-ChildItem -Path public,src,scripts,config,templates -Filter *.php -Recurse
$blockedPatterns = @(
    @{ Pattern = '\bstr_starts_with\s*\('; Label = 'str_starts_with is PHP 8-only' },
    @{ Pattern = '\bstr_ends_with\s*\('; Label = 'str_ends_with is PHP 8-only' },
    @{ Pattern = '\bstr_contains\s*\('; Label = 'str_contains is PHP 8-only' },
    @{ Pattern = '\bmatch\s*\('; Label = 'match expression is PHP 8-only' },
    @{ Pattern = '\?\->'; Label = 'nullsafe operator is PHP 8-only' },
    @{ Pattern = '#\['; Label = 'attributes are PHP 8-only' },
    @{ Pattern = 'function\s+__construct\s*\([^)]*\b(public|protected|private)\s+\$'; Label = 'constructor property promotion is PHP 8-only' }
)

foreach ($file in $phpFiles) {
    $content = Get-Content -Path $file.FullName -Raw
    foreach ($blocked in $blockedPatterns) {
        if ($content -match $blocked.Pattern) {
            throw "$($blocked.Label): $($file.FullName)"
        }
    }
}

Write-Host "No obvious PHP 8-only constructs were found in public/, src/, scripts/, config/, or templates/."
