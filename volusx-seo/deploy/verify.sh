#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# verify.sh — تست بستهٔ اصلاحات Volusx SEO از سیستم خودتان
# (سندباکس من به volusx.com دسترسی ندارد؛ این اسکریپت را روی لپ‌تاپ/سرور خودتان اجرا کنید)
#
# استفاده:
#   bash verify.sh
#   bash verify.sh https://volusx.com "/product/reyoungel-revital/"
# ---------------------------------------------------------------------------

set -uo pipefail

BASE="${1:-https://volusx.com}"
PRODUCT="${2:-/product/reyoungel-revital/}"
PASS=0
FAIL=0

ok()   { printf '  \033[32m✓\033[0m %s\n' "$1"; PASS=$((PASS+1)); }
bad()  { printf '  \033[31m✗\033[0m %s\n' "$1"; FAIL=$((FAIL+1)); }
info() { printf '  \033[36m·\033[0m %s\n' "$1"; }
head_() { printf '\n\033[1m%s\033[0m\n' "$1"; }

head_ "۱) robots.txt — بلوک اصلاحات"
ROBOTS="$(curl -s -m 20 "$BASE/robots.txt")"
if grep -q "BEGIN Volusx SEO" <<<"$ROBOTS"; then
  ok "بلوک Volusx SEO موجود است"
else
  bad "بلوک Volusx SEO پیدا نشد (mu-plugin نصب نشده؟)"
fi
grep -q "Allow: /en/" <<<"$ROBOTS" && ok "/en/ مجاز است" || bad "/en/ مجاز نیست"
grep -qi "Disallow: /checkout/" <<<"$ROBOTS" && ok "checkout مسدود است" || info "checkout در robots نیست"

head_ "۲) HTTPS — ریدایرکت از http"
CODE="$(curl -s -o /dev/null -m 15 -w '%{http_code}' -L --max-redirs 0 "http://${BASE#https://}/" 2>/dev/null)"
CODE_NOFOLLOW="$(curl -s -o /dev/null -m 15 -w '%{http_code}' "http://${BASE#https://}/" 2>/dev/null)"
if [[ "$CODE_NOFOLLOW" == "301" || "$CODE_NOFOLLOW" == "302" ]]; then
  ok "http با $CODE_NOFOLLOW به https منتقل می‌شود"
else
  bad "http کد $CODE_NOFOLLOW برگرداند (باید 301 باشد)"
fi

head_ "۳) صفحهٔ محصول"
BODY="$(curl -s -m 25 "$BASE$PRODUCT")"

if grep -qo '<meta name="robots"[^>]*noindex' <<<"$BODY"; then
  bad "صفحهٔ محصول noindex است"
else
  ok "صفحهٔ محصول noindex نیست"
fi

grep -q 'max-image-preview' <<<"$BODY" && ok "max-image-preview تنظیم شده" || info "max-image-preview دیده نشد"

head_ "۴) ساختار دادهٔ محصول (Product schema)"
if grep -q '"@type":"Product"' <<<"$BODY" || grep -q '"@type": "Product"' <<<"$BODY"; then
  ok "Product schema وجود دارد"
else
  info "Product schema در HTML اولیه پیدا نشد (ممکن است در JS باشد)"
fi

if grep -qE '"offers"' <<<"$BODY"; then
  ok "فیلد offers وجود دارد"
else
  bad "فیلد offers غایب است — خطای ۴۶ آیتم رفع نشده"
fi

if grep -qE '"priceCurrency":"(IRR)"|"priceCurrency": "(IRR)"' <<<"$BODY"; then
  ok "priceCurrency = IRR (ISO معتبر)"
elif grep -qE '"priceCurrency"' <<<"$BODY"; then
  info "priceCurrency هست اما IRR نیست — بررسی کنید"
else
  info "priceCurrency دیده نشد"
fi

if grep -qE '"reviewCount"\s*:\s*"?0"?' <<<"$BODY"; then
  bad "aggregateRating با reviewCount صفر باقی مانده"
else
  ok "aggregateRating بی‌اعتبار حذف شده"
fi

head_ "۵) LCP — preload تصویر"
if grep -q 'rel="preload" as="image"' <<<"$BODY"; then
  ok "preload تصویر اصلی محصول وجود دارد"
else
  info "preload پیدا نشد (اگر محصول تصویر شاخص ندارد، طبیعی است)"
fi
if grep -q 'fetchpriority="high"' <<<"$BODY"; then
  ok "fetchpriority=high تنظیم شده"
else
  info "fetchpriority=high دیده نشد"
fi

head_ "۶) ادغام Hyomax/Hyamax — ریدایرکت ۳۰۱"
for pair in "/product/hyamax-extra-deep-filler/:/product/hyomax-extra-deep/" \
            "/product/فیلر-hyamax-caha-lido/:/product/hyomax-caha/"; do
  FROM="${pair%%:*}"
  TO="${pair##*:}"
  ENC="$(python3 -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))" "$FROM" 2>/dev/null || echo "$FROM")"
  RES="$(curl -s -o /dev/null -m 15 -w '%{http_code} -> %{redirect_url}' "$BASE$ENC" 2>/dev/null)"
  if [[ "$RES" == 301* ]]; then
    ok "$FROM → $TO  ($RES)"
  else
    info "$FROM  ($RES)"
  fi
done

head_ "۷) پاک بودن یک ۴۰۴"
RES404="$(curl -s -o /dev/null -m 15 -w '%{http_code}' "$BASE/this-page-should-not-exist-xyz/" 2>/dev/null)"
if [[ "$RES404" == "404" ]]; then
  ok "صفحهٔ ناموجود درست ۴۰۴ برمی‌گرداند"
else
  info "کد برگشتی: $RES404 (اگر 301 است، نقشهٔ ریدایرکت خیلی گسترده شده)"
fi

printf '\n\033[1mنتیجه: %d موفق، %d ناموفق\033[0m\n' "$PASS" "$FAIL"
[[ "$FAIL" -eq 0 ]] && printf '\033[32mهمهٔ بررسی‌های حیاتی پاس شدند.\033[0m\n' || printf '\033[33mموارد ناموفق را بررسی کنید.\033[0m\n'
exit 0
