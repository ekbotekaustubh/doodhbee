#!/usr/bin/env python3
"""
DoodhBee Logo & Icon Generator
Generates high-resolution multi-size assets for Web, Mobile, Favicons, and Invoicing.
"""

import os
import subprocess
import math

SOURCE_IMG = "/home/kaustubh/.gemini/antigravity-cli/brain/7f3556b0-694b-4022-8f1f-7b048cd5a6a2/.user_uploaded/uploaded_media_1794662866600.jpg"
WEB_LOGO_DIR = "/var/www/html/doodhbee/public/assets/images/logo"
PUBLIC_DIR = "/var/www/html/doodhbee/public"
MOBILE_RES_DIR = "/var/www/html/doodhbee/mobile/android/app/src/main/res"
MOBILE_ASSETS_DIR = "/var/www/html/doodhbee/mobile/assets/images"

os.makedirs(WEB_LOGO_DIR, exist_ok=True)
os.makedirs(MOBILE_ASSETS_DIR, exist_ok=True)

# Step 1: Clean master image (remove bottom right spark watermark)
print("1. Creating pristine master image...")
master_png = "/tmp/doodhbee_master_clean.png"
# Sample area around watermark: 1820..1960 x, 1840..1945 y
# The exact background is #e8ab06
cmd_clean = [
    "magick", SOURCE_IMG,
    "-fill", "#e8ab06",
    "-draw", "rectangle 1820,1845 1960,1945",
    master_png
]
subprocess.run(cmd_clean, check=True)

# Step 2: Crop Bee Icon Mark (clean square, centered with proper margin)
# Bee bounding box is x: 565..1434, y: 398..1328
# Width = 869, Height = 930. Center is (1000, 863)
# Let us crop 920x960 centered at (1000, 863) => crop offset +540+383
# Then place onto square 1160x1160 canvas with #e8ab06 background
print("2. Generating Master Bee Icon Mark...")
bee_icon_master = f"{WEB_LOGO_DIR}/doodhbee-icon-master.png"
subprocess.run([
    "magick", master_png,
    "-crop", "920x960+540+383",
    "+repage",
    "-gravity", "center",
    "-background", "#e8ab06",
    "-extent", "1160x1160",
    bee_icon_master
], check=True)

# Step 3: Crop Text Mark
print("3. Generating Text Mark...")
text_master = "/tmp/doodhbee_text_master.png"
subprocess.run([
    "magick", master_png,
    "-crop", "1315x242+342+1381",
    "+repage",
    text_master
], check=True)

# Step 4: Generate Full Logo in Various Sizes (with brand background)
print("4. Resizing Full Logo with brand background...")
sizes = [
    ("doodhbee-logo.png", 800),
    ("doodhbee-logo-512.png", 512),
    ("doodhbee-logo-256.png", 256),
    ("doodhbee-logo-128.png", 128),
    ("doodhbee-logo-64.png", 64),
]
for fname, size in sizes:
    dest = os.path.join(WEB_LOGO_DIR, fname)
    subprocess.run(["magick", master_png, "-resize", f"{size}x{size}", dest], check=True)

# Step 5: Generate Bee Icon in Various Sizes
print("5. Resizing Bee Icon...")
icon_sizes = [
    ("doodhbee-icon.png", 512),
    ("doodhbee-icon-512.png", 512),
    ("doodhbee-icon-256.png", 256),
    ("doodhbee-icon-192.png", 192),
    ("doodhbee-icon-128.png", 128),
    ("doodhbee-icon-64.png", 64),
    ("doodhbee-icon-32.png", 32),
    ("doodhbee-icon-16.png", 16),
]
for fname, size in icon_sizes:
    dest = os.path.join(WEB_LOGO_DIR, fname)
    subprocess.run(["magick", bee_icon_master, "-resize", f"{size}x{size}", dest], check=True)

# Step 6: Generate Rounded & Circular Icons
print("6. Creating Rounded Squircle & Circular Badges...")
# Circular badge
dest_circle = os.path.join(WEB_LOGO_DIR, "doodhbee-icon-circle.png")
subprocess.run([
    "magick", "-size", "512x512", "xc:none",
    "-fill", "#e8ab06", "-draw", "circle 256,256 256,4",
    "(", f"{WEB_LOGO_DIR}/doodhbee-icon-512.png", "-resize", "440x440", ")",
    "-gravity", "center", "-composite",
    dest_circle
], check=True)

# Rounded squircle badge (border radius 96 on 512x512)
dest_rounded = os.path.join(WEB_LOGO_DIR, "doodhbee-icon-rounded.png")
subprocess.run([
    "magick", "-size", "512x512", "xc:none",
    "-fill", "#e8ab06", "-draw", "roundrectangle 0,0 511,511 96,96",
    "(", f"{WEB_LOGO_DIR}/doodhbee-icon-512.png", "-resize", "460x460", ")",
    "-gravity", "center", "-composite",
    dest_rounded
], check=True)

# Step 7: Generate Horizontal Logo (Bee on Left, Text on Right)
print("7. Generating Horizontal Logo...")
# Canvas 600x150, bee height 130, text height 62
dest_h = os.path.join(WEB_LOGO_DIR, "doodhbee-horizontal.png")
dest_h_badge = os.path.join(WEB_LOGO_DIR, "doodhbee-horizontal-badge.png")
subprocess.run([
    "magick", "-size", "620x150", "xc:#e8ab06",
    "(", bee_icon_master, "-resize", "130x130", ")", "-geometry", "+18+10", "-composite",
    "(", text_master, "-resize", "x62", ")", "-geometry", "+160+44", "-composite",
    dest_h
], check=True)

# Horizontal with rounded pill shape
subprocess.run([
    "magick", "-size", "620x150", "xc:none",
    "-fill", "#e8ab06", "-draw", "roundrectangle 0,0 619,149 32,32",
    "(", dest_h, ")", "-composite",
    dest_h_badge
], check=True)

# Step 8: Generate Transparent Logo & Transparent Icon
print("8. Generating Transparent Versions...")
trans_master = "/tmp/trans.png"
if os.path.exists(trans_master):
    # Full logo transparent
    subprocess.run(["magick", trans_master, "-resize", "800x800", os.path.join(WEB_LOGO_DIR, "doodhbee-logo-transparent.png")], check=True)
    subprocess.run(["magick", trans_master, "-resize", "512x512", os.path.join(WEB_LOGO_DIR, "doodhbee-logo-transparent-512.png")], check=True)
    subprocess.run(["magick", trans_master, "-resize", "256x256", os.path.join(WEB_LOGO_DIR, "doodhbee-logo-transparent-256.png")], check=True)
    
    # Icon transparent
    dest_icon_trans = os.path.join(WEB_LOGO_DIR, "doodhbee-icon-transparent.png")
    subprocess.run([
        "magick", trans_master,
        "-crop", "920x960+540+383",
        "+repage",
        "-gravity", "center",
        "-background", "none",
        "-extent", "1160x1160",
        "-resize", "512x512",
        dest_icon_trans
    ], check=True)
    subprocess.run(["magick", dest_icon_trans, "-resize", "256x256", os.path.join(WEB_LOGO_DIR, "doodhbee-icon-transparent-256.png")], check=True)

# Step 9: Favicons & Web App Icons
print("9. Generating Web Favicons & Apple Touch Icons...")
fav_32 = os.path.join(PUBLIC_DIR, "favicon-32x32.png")
fav_16 = os.path.join(PUBLIC_DIR, "favicon-16x16.png")
fav_48 = os.path.join(PUBLIC_DIR, "favicon-48x48.png")
fav_ico = os.path.join(PUBLIC_DIR, "favicon.ico")
fav_png = os.path.join(PUBLIC_DIR, "favicon.png")
apple_touch = os.path.join(PUBLIC_DIR, "apple-touch-icon.png")
android_192 = os.path.join(PUBLIC_DIR, "android-chrome-192x192.png")
android_512 = os.path.join(PUBLIC_DIR, "android-chrome-512x512.png")

# Use rounded squircle for favicons and app icons so they look stunning on both dark and light browser tabs
subprocess.run(["magick", dest_rounded, "-resize", "16x16", fav_16], check=True)
subprocess.run(["magick", dest_rounded, "-resize", "32x32", fav_32], check=True)
subprocess.run(["magick", dest_rounded, "-resize", "48x48", fav_48], check=True)
subprocess.run(["magick", dest_rounded, "-resize", "32x32", fav_png], check=True)
subprocess.run(["magick", dest_rounded, "-resize", "180x180", apple_touch], check=True)
subprocess.run(["magick", dest_rounded, "-resize", "192x192", android_192], check=True)
subprocess.run(["magick", dest_rounded, "-resize", "512x512", android_512], check=True)

# Generate multi-layer ICO with 16, 32, 48
subprocess.run([
    "magick", fav_16, fav_32, fav_48, fav_ico
], check=True)

# Step 10: Android Mipmap App Icons
print("10. Updating Android Launcher Icons...")
android_mipmaps = [
    ("mipmap-mdpi", 48),
    ("mipmap-hdpi", 72),
    ("mipmap-xhdpi", 96),
    ("mipmap-xxhdpi", 144),
    ("mipmap-xxxhdpi", 192),
]
for folder, px in android_mipmaps:
    target_path = os.path.join(MOBILE_RES_DIR, folder, "ic_launcher.png")
    if os.path.exists(os.path.dirname(target_path)):
        subprocess.run(["magick", dest_rounded, "-resize", f"{px}x{px}", target_path], check=True)
        print(f"   -> Updated {target_path} ({px}x{px})")

# Step 11: Mobile In-App Assets
print("11. Copying Mobile Assets...")
subprocess.run(["magick", master_png, "-resize", "512x512", os.path.join(MOBILE_ASSETS_DIR, "logo.png")], check=True)
subprocess.run(["magick", dest_rounded, "-resize", "256x256", os.path.join(MOBILE_ASSETS_DIR, "logo_icon.png")], check=True)
subprocess.run(["magick", dest_h_badge, "-resize", "480x116", os.path.join(MOBILE_ASSETS_DIR, "logo_horizontal.png")], check=True)

print("All logo assets created successfully!")
