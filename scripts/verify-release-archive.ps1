[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ArchivePath
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $ArchivePath -PathType Leaf)) {
    throw "Release archive was not found: $ArchivePath"
}

$requiredFiles = @(
    'sidrena-cijena/sidrena-cijena.php',
    'sidrena-cijena/readme.txt',
    'sidrena-cijena/LICENSE',
    'sidrena-cijena/NOTICE.md',
    'sidrena-cijena/includes/cjenik.php',
    'sidrena-cijena/src/Config.php',
    'sidrena-cijena/src/Catalog/State.php',
    'sidrena-cijena/src/Catalog/StreamWriters.php',
    'sidrena-cijena/src/Catalog/CatalogEngine.php'
)

Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::OpenRead((Resolve-Path -LiteralPath $ArchivePath).Path)
try {
    $names = @($zip.Entries.FullName)
    if ($names.Count -ne $requiredFiles.Count) { throw "Unexpected archive entry count: $($names.Count)" }
    foreach ($required in $requiredFiles) {
        if ($required -notin $names) { throw "Missing archive entry: $required" }
    }
} finally {
    $zip.Dispose()
}

Write-Output "Verified $ArchivePath"
