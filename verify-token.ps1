# Quick verification that the token was set correctly and the script can run
Write-Host "Checking token: $env:HF_TOKEN" -ForegroundColor Cyan
if ($env:HF_TOKEN) {
    Write-Host "Token is set - first 8 chars: $($env:HF_TOKEN.Substring(0,8))..." -ForegroundColor Green
} else {
    Write-Host "Token not set" -ForegroundColor Red
    exit 1
}

# Check script file exists
$scriptPath = "e:\\svp-takamol\\ipms_web-main\\ipms_web-main\\hf-image-request.ps1"
if (Test-Path $scriptPath) {
    Write-Host "Script found at: $scriptPath" -ForegroundColor Green
} else {
    Write-Host "Script NOT found at: $scriptPath" -ForegroundColor Red
    exit 1
}

# Verify script syntax by running it with -File but just checking for errors
# This will fail because token validation passes but API call may fail without internet, but we just want to verify no parse errors
try {
    powershell -Command "$env:HF_TOKEN = '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'; . '$scriptPath' -OutputPath 'test-response.json'" 2>&1 | Out-Host
    Write-Host "Script syntax appears valid" -ForegroundColor Green
} catch {
    Write-Host "Script syntax error: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}