import re
import os

base_dir = r"c:\peoples-priorities\frontend\nirdhar"
html_path = os.path.join(base_dir, "index.html")
php_path = os.path.join(base_dir, "index.php")
assets_css_dir = os.path.join(base_dir, "assets", "css")
assets_js_dir = os.path.join(base_dir, "assets", "js")

os.makedirs(assets_css_dir, exist_ok=True)
os.makedirs(assets_js_dir, exist_ok=True)

with open(html_path, "r", encoding="utf-8") as f:
    html_content = f.read()

# Extract CSS
css_match = re.search(r'<style>(.*?)</style>', html_content, re.DOTALL)
if css_match:
    css_content = css_match.group(1).strip()
    with open(os.path.join(assets_css_dir, "style.css"), "w", encoding="utf-8") as f:
        f.write(css_content)

# Extract JS
js_match = re.search(r'<script>(.*?)</script>', html_content, re.DOTALL)
if js_match:
    js_content = js_match.group(1).strip()
    
    # FIX iOS AUDIO BUG inline
    js_content = js_content.replace("{ mimeType: 'audio/webm' }", "")
    js_content = js_content.replace("const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });", "const audioBlob = new Blob(audioChunks);")
    js_content = js_content.replace("mime_type: 'audio/webm'", "mime_type: audioBlob.type || 'audio/webm'")
    
    with open(os.path.join(assets_js_dir, "main.js"), "w", encoding="utf-8") as f:
        f.write(js_content)

# Replace in index.html
new_html = re.sub(r'<style>.*?</style>', '<link rel="stylesheet" href="assets/css/style.css">', html_content, flags=re.DOTALL)
new_html = re.sub(r'<script>.*?</script>', '<script src="assets/js/main.js"></script>', new_html, flags=re.DOTALL)

with open(html_path, "w", encoding="utf-8") as f:
    f.write(new_html)

# Replace in index.php
with open(php_path, "r", encoding="utf-8") as f:
    php_content = f.read()

new_php = re.sub(r'<style>.*?</style>', '<link rel="stylesheet" href="assets/css/style.css">', php_content, flags=re.DOTALL)
new_php = re.sub(r'<script>.*?</script>', '<script src="assets/js/main.js"></script>', new_php, flags=re.DOTALL)

with open(php_path, "w", encoding="utf-8") as f:
    f.write(new_php)

print("Refactoring complete.")
