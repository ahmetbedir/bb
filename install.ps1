# Download the latest standalone bb binary for Windows and install it.
# Published as a release asset, so a plain irm works for everyone:
#
#   irm https://github.com/OWNER/REPO/releases/latest/download/install.ps1 | iex

$ErrorActionPreference = 'Stop'

$repo  = '@@REPO@@'
$asset = 'bb-windows-x86_64.exe'
$url   = "https://github.com/$repo/releases/latest/download/$asset"

$dir = Join-Path $env:LOCALAPPDATA 'bb'
New-Item -ItemType Directory -Force -Path $dir | Out-Null
$target = Join-Path $dir 'bb.exe'

Write-Host "==> Downloading $asset"
Invoke-WebRequest -Uri $url -OutFile $target

# Add the install directory to the user PATH if it isn't there already.
$userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
if ($userPath -notlike "*$dir*") {
    [Environment]::SetEnvironmentVariable('Path', "$userPath;$dir", 'User')
    Write-Host "==> Added $dir to your PATH (restart your terminal)."
}

Write-Host "==> Installed to $target"
& $target --version

Write-Host ""
Write-Host "Next steps:"
Write-Host "  bb configure          # or: bb auth login"
Write-Host "  bb auth setup-git"
