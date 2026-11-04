#!/usr/bin/env bash
# Build and verify an installable WordPress plugin zip.
set -euo pipefail
cd "$(dirname "$0")"

ZIP="plugin-harmony.zip"
SLUG="plugin-harmony"

rm -f "$ZIP"

zip -r -q "$ZIP" "$SLUG" \
  -x "*/.DS_Store" \
  -x "__MACOSX/*" \
  -x "*/.*"

echo "ساخته شد: $(pwd)/$ZIP"

# ---------------------------------------------------------------- verification
fail() { echo "خطا: $1" >&2; exit 1; }

command -v unzip >/dev/null || fail "unzip نصب نیست"

# 1. Exactly one top-level folder, named like the plugin slug.
tops="$(unzip -Z1 "$ZIP" | cut -d/ -f1 | sort -u | grep -v '^$' || true)"
[ "$tops" = "$SLUG" ] || fail "ساختار زیپ باید یک پوشهٔ $SLUG داشته باشد، نه: $tops"

# 2. Main plugin file with a valid header.
unzip -p "$ZIP" "$SLUG/plugin-harmony.php" >/dev/null 2>&1 || fail "فایل اصلی افزونه در زیپ نیست"
HEADER="$(unzip -p "$ZIP" "$SLUG/plugin-harmony.php" | head -20)"
echo "$HEADER" | grep -q "Plugin Name:" || fail "هدر Plugin Name پیدا نشد"
echo "$HEADER" | grep -q "Version:"      || fail "هدر Version پیدا نشد"
echo "$HEADER" | grep -q "Text Domain:"  || fail "هدر Text Domain پیدا نشد"

# 3. No UTF-8 BOM (it breaks the plugin header in WordPress).
first_bytes="$(unzip -p "$ZIP" "$SLUG/plugin-harmony.php" | head -c 3 | od -An -tx1 | tr -d ' \n')"
[ "$first_bytes" != "efbbbf" ] || fail "فایل اصلی BOM دارد"

# 4. Every PHP file starts with a PHP tag and none closes the tag.
while IFS= read -r file; do
  [ -n "$file" ] || continue
  unzip -p "$ZIP" "$file" | head -c 5 | grep -q '<?php' || fail "فایل بدون تگ شروع PHP: $file"
  if unzip -p "$ZIP" "$file" | grep -q '?>[[:space:]]*$'; then
    fail "فایل با تگ پایانی PHP تمام می‌شود: $file"
  fi
done < <(unzip -Z1 "$ZIP" | grep '\.php$')

# 5. No development leftovers inside the package.
if unzip -Z1 "$ZIP" | grep -Eq '(node_modules|\.git/|tests?/|\.DS_Store)'; then
  fail "فایل‌های توسعه داخل بسته است"
fi

echo "بررسی بسته: موفق"
echo "فایل‌ها: $(unzip -Z1 "$ZIP" | grep -v '/$' | wc -l) — حجم: $(du -h "$ZIP" | cut -f1)"
