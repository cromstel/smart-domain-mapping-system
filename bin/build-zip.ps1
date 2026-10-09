param(
    # Optional version override; read from the plugin header when omitted.
    [string]$Version = ''
)
$ErrorActionPreference = 'Stop'

$src = 'smart-domain-mapping-system'
$pluginFile = Join-Path $src 'domain-mapping-system.php'

if (-not (Test-Path $pluginFile)) {
    throw "Plugin main file not found: $pluginFile"
}

# Read the version from the plugin header when not passed explicitly.
if (-not $Version) {
    $match = [regex]::Match(
        [IO.File]::ReadAllText($pluginFile),
        '(?m)^\s*\*\s*Version:\s*(\S+)'
    )
    if (-not $match.Success) { throw 'Could not read Version from plugin header.' }
    $Version = $match.Groups[1].Value
}

# Stage a clean copy under _build/ (dev-only files excluded below).
if (Test-Path '_build') { Remove-Item -Recurse -Force '_build' }
New-Item -ItemType Directory -Path '_build' -Force | Out-Null
$stage = Join-Path '_build' $src
Copy-Item $src $stage -Recurse

# Dev-only exclusions. CI runs this same script, so local and release
# artifacts can never drift.
Remove-Item -Recurse -Force (Join-Path $stage '.github'), (Join-Path $stage 'tests') -ErrorAction SilentlyContinue
Remove-Item -Force (Join-Path $stage 'phpunit.xml.dist'), (Join-Path $stage 'SECURITY_AUDIT.md') -ErrorAction SilentlyContinue

# The ZIP root folder is the plugin slug (version lives in the file name),
# so WordPress installs/upgrades into a stable directory.
$zip = "$src-$Version.zip"
Compress-Archive -Path $stage -DestinationPath $zip -Force
Write-Output "Built $zip (folder: $src, version: $Version)"
