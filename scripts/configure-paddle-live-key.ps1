$secureKey = Read-Host 'Paste Paddle Live API key (input is hidden)' -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureKey)

try {
    $plainKey = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)

    if (-not $plainKey.StartsWith('pdl_live_apikey_')) {
        throw 'This does not look like a Paddle Live API key.'
    }

    [Environment]::SetEnvironmentVariable('PADDLE_LIVE_API_KEY', $plainKey, 'User')
    Write-Host 'Paddle Live API key saved securely for your Windows user.' -ForegroundColor Green
}
finally {
    if ($pointer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    }
    Remove-Variable plainKey -ErrorAction SilentlyContinue
    Remove-Variable secureKey -ErrorAction SilentlyContinue
}
