# 🚀 GitHub Push + VS Code রান গাইড (FF Arena)

## ধাপ ০ — যা জিপে আছে / নেই
- ✅ পুরো source (Laravel + Flutter mobile + Go/Rust payment services + tests + docs)
- ✅ `.git` history (push-ready) · `.env` (লোকাল রানের জন্য, GitHub-এ যাবে না) · `composer.lock`
- ⛔ ইচ্ছাকৃত বাদ: `vendor/`, `node_modules/`, `database.sqlite`, logs — এগুলো source নয়, নিচের কমান্ডে তৈরি হয়।
- প্রমাণ: `FILE_AUDIT.md` (94 ফাইল যাচাইকৃত, MISSING: 0)

## ধাপ ১ — VS Code-এ চালানো (Laravel web)
```bash
unzip ff-arena-final-clean.zip && cd ff-arena-final-clean
composer install            # PHP 8.4 + extensions: mbstring, xml, curl, sqlite3, bcmath, zip, intl
npm install                 # Node 18+
php artisan key:generate    # .env আছে, শুধু APP_KEY নিশ্চিত করুন
php artisan migrate         # database/database.sqlite তৈরি হবে
php artisan serve           # → http://127.0.0.1:8000
```
টেস্ট চালাতে: `php artisan test` (সবুজ: 1297 passed / 0 failed)
কোড স্টাইল: `./vendor/bin/pint`

## ধাপ ২ — GitHub-এ push (শুধু source যাবে)
`.gitignore` আগে থেকেই ঠিক আছে — `.env`, sqlite, logs, vendor কিছুই যাবে না।

```bash
# GitHub-এ নতুন EMPTY repo তৈরি করুন (README ছাড়া), তারপর:
git remote add origin https://github.com/<তোমার-username>/<repo-name>.git
git branch -M main
git push -u origin main --force
```

Push-এর পর GitHub-এ যা দেখবে: শুধু পরিষ্কার source — 3.4k ফাইল, কোনো সিক্রেট নয়
(`.env`-এর APP_KEY/providers লোকালেই থাকে; production-এ `.env.example` অনুযায়ী নতুন ভ্যালু বসাবে)।

## ধাপ ৩ — Mobile (Flutter) চালাতে চাইলে
```bash
cd mobile
flutter pub get
flutter run
```
(মোবাইল অ্যাপ API base URL: `mobile/lib/` এ কনফিগারড — লোকাল টেস্টে নিজের PC-র IP দিন।)

## ধাপ ৪ — Go/Rust payment microservices (ঐচ্ছিক)
Laravel ডিফল্টভাবে নিজের bkash flow ব্যবহার করে; microservices চালু করতে:
```bash
cd services/payment-gateway-go && go run ./cmd/server   # অথবা docker-compose.yml দেখুন
```

## স্ট্যাটাস (সর্বশেষ যাচাই)
| বিষয় | ফলাফল |
|---|---|
| ফুল টেস্ট স্যুট | **1297 passed / 0 failed** |
| Marketing স্যুট (12) | **127 tests, সব সবুজ** |
| রুট সেন্সাস | 2160 routes, MISSING: 0, ডুপ্লিকেট নাম: 0 |
| Migrations | 66 applied, 0 pending |
| php -l (সব ফাইল) | OK · pint OK |
| লঞ্চ ব্লকার | **নেই** |
