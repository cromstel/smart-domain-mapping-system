param(
    [Parameter(Mandatory = $true)]
    [string]$Version
)
$ErrorActionPreference = 'Stop'

if ($Version -notmatch '^\d+\.\d+\.\d+$') {
    throw "Version must look like 1.2.3 (got: $Version)"
}

$src      = 'smart-domain-mapping-system'
$plugin   = Join-Path $src 'domain-mapping-system.php'
$readme   = Join-Path $src 'readme.txt'
$utf8     = New-Object System.Text.UTF8Encoding($false)

foreach ($file in @($plugin, $readme)) {
    if (-not (Test-Path $file)) { throw "Missing file: $file" }
}

# Plugin header: " * Version: x.y.z" and define( 'DMS_VERSION', 'x.y.z' ).
$content = [IO.File]::ReadAllText((Resolve-Path $plugin), $utf8)
$content = $content -replace '(?m)^(\s*\*\s*Version:\s*).+$', ('${1}' + $Version)
$content = $content -replace "(define\(\s*'DMS_VERSION',\s*')[^']+(')", ('${1}' + $Version + '${2}')
[IO.File]::WriteAllText((Resolve-Path $plugin), $content, $utf8)

# readme.txt: "Stable tag: x.y.z" plus a fresh changelog entry.
$rm = [IO.File]::ReadAllText((Resolve-Path $readme), $utf8)
$rm = $rm -replace '(?m)^Stable tag:\s*.+$', ('Stable tag: ' + $Version)
if ($rm -notmatch [regex]::Escape("= $Version =")) {
    $entry = "= $Version =`r`n`r`n* TODO: release notes.`r`n`r`n"
    $rm    = $rm -replace '(?m)^(== Changelog ==\r?\n)', ('${1}' + $entry)
}
[IO.File]::WriteAllText((Resolve-Path $readme), $rm, $utf8)

Write-Output "Bumped version to $Version (plugin header, DMS_VERSION, Stable tag, changelog)."
Write-Output "Next: edit the changelog entry, then commit and tag v$Version."
