# =============================================================================
# hf-image-request.ps1
# PowerShell script to call Hugging Face Router chat/completions with an image,
# then save the JSON response to a file.
#
# Prerequisites:
#   • Set your Hugging Face token once:
#       setx HF_TOKEN "hf_******************"
#     then open a **new** PowerShell window (so the variable is loaded).
#   • Optional: pass an output path via -OutputPath "C:\path\custom.json"
# =============================================================================

[CmdletBinding()]
param(
    [Parameter(Mandatory = $false)]
    [string]$OutputPath = ".\response.json"   # default file next to this script
)

# ---- Validate token ---------------------------------------------------------
if (-not $env:HF_TOKEN) {
    Write-Error "Environment variable HF_TOKEN is not set. " +
                "Run:  setx HF_TOKEN \"hf_...\"  then restart PowerShell."
    exit 1
}

# ---- Build request ----------------------------------------------------------
$headers = @{
    'Authorization' = "Bearer $($env:HF_TOKEN)"
    'Content-Type'  = 'application/json'
}

$body = @{
    model  = 'Qwen/Qwen3.8-27B:featherless-ai'
    stream = $false
    messages = @(
        @{
            role    = 'user'
            content = @(
                @{
                    type = 'text'
                    text = 'Describe this image in one sentence.'
                },
                @{
                    type       = 'image_url'
                    image_url  = @{
                        url = 'https://cdn.britannica.com/61/93061-050-99147DCE/Statue-of-Liberty-Island-New-York-Bay.jpg'
                    }
                }
            )
        }
    )
} | ConvertTo-Json -Depth 10

# ---- Execute ----------------------------------------------------------------
Write-Host "Sending request to Hugging Face Router …" -ForegroundColor Cyan
$response = Invoke-RestMethod `
    -Uri 'https://router.huggingface.co/v1/chat/completions' `
    -Headers $headers `
    -Body $body `
    -Method Post

# ---- Save pretty-printed JSON to file --------------------------------------
$prettyJson = $response | ConvertTo-Json -Depth 10
$prettyJson | Set-Content -Path $OutputPath -Encoding utf8
Write-Host "Response saved to $((Resolve-Path $OutputPath).Path)" -ForegroundColor Green

# ---- Optional: show on console ---------------------------------------------
$prettyJson