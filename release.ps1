<#
.SYNOPSIS
  Builds a plugin zip and publishes it as a GitHub release.

.DESCRIPTION
  - Reads the version from the "Version:" header in gigio-mailshot.php
  - Requires a clean working tree on main, in sync with origin
  - Zips only the tracked files listed in $Files (via git archive, so the
    gitignored mailchimp-keys.php can never leak into a release)
  - Tags v<version>, pushes the tag, and creates the release with gh

.PARAMETER Notes
  Release notes. If omitted, GitHub auto-generates them from commits.

.PARAMETER DryRun
  Build the zip and run all checks, but don't tag, push or publish.

.EXAMPLE
  .\release.ps1
  .\release.ps1 -Notes "Exclude shortcode content from summaries"
  .\release.ps1 -DryRun
#>
param(
    [string]$Notes,
    [switch]$DryRun
)

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

$Slug  = 'gigio-mailshot'
$Files = @('gigio-mailshot.php', 'README.md')   # contents of the release zip

function Run-Git {
    $out = & git @args
    if ($LASTEXITCODE -ne 0) { throw "git $($args -join ' ') failed" }
    $out
}

# Version from plugin header
$header = Select-String -Path "$Slug.php" -Pattern '^\s*\*\s*Version:\s*(\S+)' | Select-Object -First 1
if (-not $header) { throw "No Version header found in $Slug.php" }
$version = $header.Matches[0].Groups[1].Value
$tag = "v$version"

# Pre-flight checks
if (-not (Get-Command gh -ErrorAction SilentlyContinue)) { throw "GitHub CLI (gh) not found" }
if ((Run-Git branch --show-current) -ne 'main') { throw "Not on main branch" }
if (Run-Git status --porcelain) { throw "Working tree not clean - commit or stash changes first" }
Run-Git fetch origin --tags | Out-Null
if ((Run-Git rev-parse HEAD) -ne (Run-Git rev-parse origin/main)) { throw "main is not in sync with origin/main - push or pull first" }
if (Run-Git tag --list $tag) { throw "Tag $tag already exists - bump Version in $Slug.php" }

# Build zip: <slug>/<files> from HEAD
$outDir = Join-Path ([IO.Path]::GetTempPath()) "$Slug-release"
New-Item -ItemType Directory -Force $outDir | Out-Null
$zip = Join-Path $outDir "$Slug-$version.zip"
Remove-Item $zip -ErrorAction SilentlyContinue
Run-Git archive --format=zip "--prefix=$Slug/" -o $zip HEAD @Files | Out-Null
Write-Host "Built $zip"

if ($DryRun) {
    Write-Host "Dry run: would tag and release $tag. Stopping."
    return
}

# Tag, push, publish
Run-Git tag -a $tag -m "Release $version" | Out-Null
Run-Git push origin $tag | Out-Null

$ghArgs = @('release', 'create', $tag, $zip, '--title', "$Slug $version")
if ($Notes) { $ghArgs += @('--notes', $Notes) } else { $ghArgs += '--generate-notes' }
& gh @ghArgs
if ($LASTEXITCODE -ne 0) { throw "gh release create failed (tag $tag was already pushed)" }

Write-Host "Released $tag"
