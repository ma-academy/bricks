param([string]$RepoPath = ".")

$ErrorActionPreference = "Stop"
$root = (Resolve-Path $RepoPath).Path
$utf8 = New-Object System.Text.UTF8Encoding($false)

function Replace-Exact([string]$rel, [string]$old, [string]$new) {
    $path = Join-Path $root $rel
    $text = [System.IO.File]::ReadAllText($path)
    if (-not $text.Contains($old)) {
        throw "Expected text not found in $rel"
    }
    $text = $text.Replace($old, $new)
    [System.IO.File]::WriteAllText($path, $text, $utf8)
    Write-Host "OK  $rel"
}

Replace-Exact "includes/abilities/settings.php" `
"return [`t`t`t'apiKeyUnsplash'" `
"return [`r`n`t`t`t'apiKeyUnsplash'"

Replace-Exact "includes/abilities/settings.php" `
"}`t`treturn self::value_is_configured( constant( `$constant ) );" `
"}`r`n`r`n`t`treturn self::value_is_configured( constant( `$constant ) );"

Replace-Exact "includes/admin.php" `
");`t}" `
");`r`n`t}"

Replace-Exact "includes/builder.php" `
"die;`r`n`t`t}`t`t// STEP: Return if current user can not edit this post" `
"die;`r`n`t`t}`r`n`r`n`t`t// STEP: Return if current user can not edit this post"

Write-Host ""
Write-Host "Formatting cleanup applied."
