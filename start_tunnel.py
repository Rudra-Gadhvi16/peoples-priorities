import urllib.request
import os
import subprocess

exe_path = "cloudflared.exe"

if not os.path.exists(exe_path) or os.path.getsize(exe_path) < 1000000:
    print("Downloading Cloudflare Tunnel (this takes about 5-10 seconds)...")
    url = "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe"
    req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
    with urllib.request.urlopen(req) as response, open(exe_path, 'wb') as out_file:
        out_file.write(response.read())
    print("Download complete!")

print("\nStarting tunnel for port 8000...")
print(">>> LOOK FOR THE URL ENDING IN .trycloudflare.com BELOW <<<\n")
print("-" * 60)
# cloudflared prints the URL to stderr
subprocess.run([exe_path, "tunnel", "--url", "http://localhost:8000"])
