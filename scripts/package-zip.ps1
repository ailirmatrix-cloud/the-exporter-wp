# Build a WordPress-uploadable plugin zip (the-exporter/the-exporter.php at archive root).
# Uses forward slashes in zip entry names (required for Linux / WordPress Studio).
param(
	[string]$PluginRoot = (Split-Path -Parent $PSScriptRoot)
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$pluginSlug = 'the-exporter'
$stagingRoot = Join-Path $env:TEMP "te-release-$([guid]::NewGuid().ToString('N'))"
$stagingDir = Join-Path $stagingRoot $pluginSlug
$zipPath = Join-Path $PluginRoot "$pluginSlug.zip"

Push-Location $PluginRoot
try {
	npm run build
	if ($LASTEXITCODE -ne 0) { throw 'npm run build failed' }
}
finally {
	Pop-Location
}

New-Item -ItemType Directory -Path $stagingDir -Force | Out-Null

$excludeDirs = @('node_modules', 'src', 'tests', '.git', '.github', '.cursor', 'scripts')
$excludeFiles = @('package.json', 'package-lock.json', '.distignore', '.editorconfig', '.eslintrc.js', '.eslintrc.cjs', '.prettierrc', '.prettierrc.js', 'the-exporter.zip')

Get-ChildItem -Path $PluginRoot -Force | ForEach-Object {
	if ($excludeDirs -contains $_.Name) { return }
	if ($excludeFiles -contains $_.Name) { return }
	$dest = Join-Path $stagingDir $_.Name
	if ($_.PSIsContainer) {
		Copy-Item -Path $_.FullName -Destination $dest -Recurse -Force
	} else {
		Copy-Item -Path $_.FullName -Destination $dest -Force
	}
}

if (Test-Path $zipPath) {
	Remove-Item $zipPath -Force
}

$zip = [System.IO.Compression.ZipFile]::Open( $zipPath, [System.IO.Compression.ZipArchiveMode]::Create )
try {
	Get-ChildItem -Path $stagingDir -Recurse -File | ForEach-Object {
		$relative = $_.FullName.Substring( $stagingDir.Length ).TrimStart( '\', '/' )
		$entryName = ( $pluginSlug + '/' + $relative ) -replace '\\', '/'
		[void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile( $zip, $_.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal )
	}
}
finally {
	$zip.Dispose()
}

Remove-Item $stagingRoot -Recurse -Force

$size = (Get-Item $zipPath).Length
Write-Host "Created $zipPath ($([math]::Round($size / 1KB, 1)) KB)"

# Sanity check: main plugin file must use forward slashes.
$check = [System.IO.Compression.ZipFile]::OpenRead( $zipPath )
$main = $check.Entries | Where-Object { $_.FullName -eq "$pluginSlug/$pluginSlug.php" }
if ( -not $main ) {
	$check.Dispose()
	throw "Zip validation failed: missing $pluginSlug/$pluginSlug.php entry"
}
$check.Dispose()
Write-Host "Validated zip entry: $pluginSlug/$pluginSlug.php"
