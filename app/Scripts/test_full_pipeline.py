"""
Full Pipeline Test: Solve → Encrypt → Login → OTP
==================================================
Tests the complete flow against a remote solver VPS.

Usage:
    python test_full_pipeline.py --solver-url http://VPS_IP:8788 --account-id 5
    
    Or without solver (manual token):
    python test_full_pipeline.py --manual-token "0.TOKEN_HERE" --account-id 5
"""

import argparse, json, sys, time

try:
    import cloudscraper
except ImportError:
    print("pip install cloudscraper")
    sys.exit(1)

API_BASE = 'https://api.ivacbd.com/iams/api/v1'
SITE_KEY = '0x4AAAAAAADnPIDROrmt1Wwj'
PAGE_URL = 'https://appointment.ivacbd.com/signin'

def solve_turnstile(solver_url):
    """Get a raw Turnstile token from the Linux solver."""
    import urllib.request
    print(f"[1/4] Solving Turnstile via {solver_url}...")
    data = json.dumps({'siteKey': SITE_KEY, 'pageUrl': PAGE_URL}).encode()
    req = urllib.request.Request(
        f'{solver_url}/solve',
        data=data,
        headers={'Content-Type': 'application/json', 'Accept': 'application/json'}
    )
    start = time.time()
    with urllib.request.urlopen(req, timeout=60) as resp:
        result = json.loads(resp.read())
    elapsed = (time.time() - start) * 1000
    print(f"  ✓ Solved in {elapsed:.0f}ms ({result.get('attempts', '?')} attempts)")
    return result['token']

def encrypt_token(raw_token):
    """Encrypt via PHP v2 (calls local sidecar or PHP)."""
    print("[2/4] Encrypting with PHP v2...")
    # Use Python re-implementation of PHP v2
    CHARSET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_'
    V2_PERM = [26,13,60,15,30,9,56,11,21,38,51,36,17,34,55,32,8,31,46,29,12,27,42,25,3,48,37,50,7,52,33,54,62,41,24,43,58,45,28,47,49,2,23,0,53,6,19,4,44,59,10,57,40,63,14,61,39,20,1,22,35,16,5,18]
    skip, enc_len = 4, 26
    prefix_len = max(0, min(skip, len(raw_token)))
    actual_len = max(0, min(enc_len, len(raw_token) - prefix_len))
    chars = list(raw_token[prefix_len:prefix_len + actual_len])
    for i in range(actual_len):
        idx = CHARSET.find(chars[i])
        if idx >= 0:
            chars[i] = CHARSET[V2_PERM[idx]]
    encrypted = raw_token[:prefix_len] + ''.join(chars) + raw_token[prefix_len + actual_len:]
    print(f"  ✓ Encrypted: {encrypted[:40]}...")
    return encrypted

def login(account_id, encrypted_token, password='aRrazzak90#'):
    """Sign in to IVAC via cloudscraper."""
    print("[3/4] Signing in to IVAC...")
    scraper = cloudscraper.create_scraper(browser={'browser': 'chrome', 'platform': 'windows', 'desktop': True})
    
    body = {
        'phone': '01778300054',
        'email': '',
        'password': password,
        'c': encrypted_token,
    }
    headers = {
        'Accept': 'application/json',
        'Origin': 'https://appointment.ivacbd.com',
        'Referer': 'https://appointment.ivacbd.com/signin',
        'x-sec-navigation-state': '80d51dc5-af20-46fa-a7bb-e6a8f3f80065',
    }
    
    resp = scraper.post(f'{API_BASE}/auth/v26-sign-in', json=body, headers=headers, timeout=30)
    print(f"  HTTP {resp.status_code}")
    
    try:
        data = resp.json()
        print(f"  Response: {json.dumps(data, indent=2, ensure_ascii=False)[:500]}")
        
        if resp.status_code == 200:
            token = data.get('data', {}).get('accessToken') or data.get('accessToken')
            request_id = data.get('data', {}).get('requestId') or data.get('requestId')
            if token:
                print(f"  ✓ Login successful! JWT: {token[:50]}...")
                print(f"  ✓ RequestId: {request_id}")
                return token, request_id
            else:
                print("  ✗ No token in response")
        else:
            print(f"  ✗ Login failed: {data.get('message', data.get('error', 'unknown'))}")
    except:
        print(f"  Raw: {resp.text[:300]}")
    
    return None, None

def main():
    parser = argparse.ArgumentParser(description='Test full Turnstile → Login → OTP pipeline')
    parser.add_argument('--solver-url', help='Linux solver URL (e.g., http://VPS_IP:8788)')
    parser.add_argument('--manual-token', help='Paste raw Turnstile token manually')
    parser.add_argument('--account-id', type=int, default=5, help='Account ID (default: 5)')
    args = parser.parse_args()
    
    print("=" * 60)
    print("  Full Pipeline Test: Solve → Encrypt → Login → OTP")
    print("=" * 60)
    print()
    
    # Step 1: Get Turnstile token
    if args.manual_token:
        raw_token = args.manual_token
        print(f"[1/4] Using manual token: {raw_token[:40]}...")
    elif args.solver_url:
        raw_token = solve_turnstile(args.solver_url)
    else:
        print("ERROR: Provide --solver-url or --manual-token")
        sys.exit(1)
    
    # Step 2: Encrypt
    encrypted = encrypt_token(raw_token)
    
    # Step 3: Login
    jwt, request_id = login(args.account_id, encrypted)
    
    if jwt:
        print()
        print("=" * 60)
        print("  ✓ PIPELINE SUCCESS")
        print(f"  JWT: {jwt[:80]}...")
        print(f"  RequestId: {request_id}")
        print("  Next: Use these to verify OTP")
        print("=" * 60)
    else:
        print()
        print("  ✗ Pipeline failed at login step")
        sys.exit(1)

if __name__ == '__main__':
    main()
