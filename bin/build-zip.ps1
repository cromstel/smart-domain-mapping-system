param(
    # Optional version override; read from the plugin header when omitted.
    [string]$Version = ''
)
$ErrorActionPreference = 'Stop'

# The plugin source lives at the repository root; the ZIP's single top-level
# folder must be the plugin slug so WordPress installs/upgrades into a stable
# directory.
$root       = (Get-Location).Path
$slug       = 'smart-domain-mapping-system'
$pluginFile = 'domain-mapping-system.php'

if (-not (Test-Path $pluginFile)) {
    throw "Plugin main file not found: $pluginFile (run this script from the repository root)"
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

# Ship only tracked files, so untracked local artifacts (planning docs, composer
# scripts, editor backups) can never leak into a release. Dev-only, build-only
# and repository-only paths are excluded on top of that.
$excludeDirs  = @( '.github', 'assets', 'bin', 'docs', 'tests' )
$excludeFiles = @(
    '.gitattributes', '.gitignore', '.phpcs.xml.dist',
    'composer.json', 'composer.lock',
    'README.md', 'CONTRIBUTING.md', 'SECURITY.md', 'phpunit.xml.dist'
)

$tracked = @(git -C $root ls-files)
if ( $tracked.Count -eq 0 ) {
    throw 'git ls-files returned no files - run this script from the repository root.'
}

# Stage a clean copy under _build/<slug>/.
if ( Test-Path '_build' ) { Remove-Item -Recurse -Force '_build' }
$stage = Join-Path '_build' $slug
New-Item -ItemType Directory -Path $stage -Force | Out-Null

foreach ( $rel in $tracked ) {
    $rel = $rel -replace '/', '\'
    $top = ( $rel -split '\\' )[0]
    if ( $excludeDirs -contains $top ) { continue }
    if ( $excludeFiles -contains $rel ) { continue }

    $dest    = Join-Path $stage $rel
    $destDir = Split-Path $dest -Parent
    if ( -not ( Test-Path $destDir ) ) {
        New-Item -ItemType Directory -Path $destDir -Force | Out-Null
    }
    Copy-Item -Path ( Join-Path $root $rel ) -Destination $dest -Force
}

# Drop version-control placeholders so they never reach a customer site.
Get-ChildItem -Path $stage -Recurse -Force -Filter '.gitkeep' -ErrorAction SilentlyContinue |
    Remove-Item -Force -ErrorAction SilentlyContinue

# The ZIP root folder is the plugin slug (version lives in the file name).
$zip = "$slug-$Version.zip"
Compress-Archive -Path $stage -DestinationPath $zip -Force
Write-Output "Built $zip (folder: $slug, version: $Version)"
