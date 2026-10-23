# FDE Transition Tracker

A single-file progress tracker for the 4-month move to **AI Application Engineer / Forward Deployed Engineer**, seeded with the plan from `Forward_Deployed_Engineer_Full_Roadmap.pdf`:

- **16 weeks** of sub-tasks (Month 1 Python/APIs → Month 2 LLMs/RAG/tools → Month 3 integration and deployment → Month 4 quality and interviews)
- the **release checklist** and **job-readiness checklist**
- the **application tracker fields**
- the **Month 2 and Month 4 checkpoints**

| File | Purpose |
|---|---|
| `index.html` | The whole app: HTML, CSS and vanilla JS. No build step, no external scripts. |
| `sync.php` | Cloud-sync endpoint for PHP hosting (section 3b). It stores one JSON file. |
| `api/sync.js` | Cloud-sync endpoint for Vercel (section 3). |
| `.htaccess` | Optional, for Apache hosts. It passes the `Authorization` header to PHP and blocks downloads of the data file. |

## 1. Open it locally

Double-click `index.html`, or open it with any modern browser (Chrome, Edge, Firefox, Safari). That's all you need.

If your browser restricts `file://` pages (some block storage there), serve the folder instead:

```bash
cd /path/to/this/folder
python3 -m http.server 8080      # or: php -S 127.0.0.1:8080
# open http://127.0.0.1:8080/index.html
```

## 2. Host it free

You only need to upload `index.html`. `sync.php` cannot run on these static hosts.

**GitHub Pages**
1. Create a public repo, for example `fde-tracker`, and add `index.html` to it.
2. Go to **Settings → Pages → Build and deployment**, choose *Deploy from a branch*, pick `main` and `/ (root)`, and save.
3. After a minute the site is live at `https://<username>.github.io/fde-tracker/` over HTTPS.

**Netlify**
1. Go to app.netlify.com → **Add new site → Deploy manually**.
2. Drag the folder that contains `index.html` onto the page.
3. You get an `https://<name>.netlify.app` URL. Rename it under *Site configuration*.

Note: hosting the page does **not** host your data. Each visitor's data stays in their own browser (see section 4).

## 3. Deploy on Vercel with cloud sync (recommended)

This puts the page and the sync endpoint on one Vercel project:
- `index.html` is the app.
- `api/sync.js` is the sync function. It stores your data in a free Upstash Redis database.
- `.vercelignore` makes sure only those two are uploaded. The PDF, `sync.php` and backups stay on your laptop.

1. **Deploy.** Pick one:
   - **Vercel CLI**, from this folder:
     ```bash
     npx vercel        # log in, accept the defaults → preview URL
     npx vercel --prod # production URL, e.g. https://fde-tracker.vercel.app
     ```
   - **GitHub:** push this folder to a repo (`.gitignore` keeps the PDF and JSON backups out), then on vercel.com choose **Add New → Project → Import** that repo. There's no build step, so keep the defaults.
2. **Add storage.** In the Vercel project, open **Storage → Create Database → Upstash for Redis (free)** and connect it to the project. This adds `KV_REST_API_URL` and `KV_REST_API_TOKEN` automatically.
3. **Add your secret.**
   - Create one: `node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"`
   - In **Settings → Environment Variables**, add `FDE_SYNC_SECRET` with that value.
4. **Redeploy** so the function picks up the new variables: **Deployments → … → Redeploy**, or run `npx vercel --prod` again.
5. **Check it works:**
   ```bash
   curl -H "Authorization: Bearer YOUR_SECRET" https://fde-tracker.vercel.app/api/sync
   # {"error":"No data stored yet"}  = working (404 until the first push)
   ```
6. **In the app** on each device, go to **Settings & sync** and set:
   - Sync URL: `https://fde-tracker.vercel.app/api/sync`
   - Sync secret key: your secret

   Press **Push to cloud** on the device that has your data, then **Pull from cloud** on the others.

Notes:
- The page and the API share one address, so you don't need CORS settings. Only set `FDE_SYNC_ORIGIN` if you host the page somewhere else.
- `api/sync.js` checks the same things as `sync.php`:
  - Bearer secret, compared in constant time
  - JSON only, 1 MB maximum
  - the data must be tracker data
  - the secret key is removed before saving, and the data is re-encoded
- It stores one record (`fde-tracker:data`), so it uses almost nothing of the free tier.
- Sync is not automatic on every change. Press Push when you finish on one device and Pull when you start on another. If both changed, the app asks which version to keep.

## 3b. Alternative: cloud sync on PHP hosting

Use this if you want Push/Pull between your laptop and phone. You need any PHP 7.4+ host with HTTPS. Most shared hosting with a free Let's Encrypt certificate works.

1. **Create a secret** of at least 24 characters:
   ```bash
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```
2. **Edit `sync.php`**, section 1 CONFIG:
   - `$SECRET`: paste the secret, or set the `FDE_SYNC_SECRET` environment variable if your host supports it.
   - `$ALLOWED_ORIGIN`: the exact origin your `index.html` is served from, with no path and no trailing slash. For example `https://your-username.github.io` or `https://my-tracker.netlify.app`. Requests from any other site are rejected (CORS).
3. **Upload** `sync.php` and `.htaccess` into a folder such as `public_html/fde/`. The folder must be writable by PHP (usually 755). The script creates `fde-tracker-data.json` next to itself, and `.htaccess` blocks direct downloads of it. On Nginx hosts, add an equivalent `location ~ \.(json|lock|tmp)$ { deny all; }` rule.
4. **Test** it from a terminal:
   ```bash
   curl -H "Authorization: Bearer YOUR_SECRET" https://example.com/fde/sync.php
   # {"error":"No data stored yet"}  means it works (404 until the first push)
   ```
   If you get `Unauthorized` even with the right key, your host is stripping the header. Make sure the `.htaccess` was uploaded.
5. **In the app**, go to **Settings & sync**, enter the Sync URL (`https://example.com/fde/sync.php`) and the secret, then press **Push to cloud**. On your other device, enter the same two values and press **Pull from cloud**.

How syncing behaves:
- Every change updates `updatedAt`.
- If both the device and the cloud changed since the last sync, the app shows both timestamps and asks which version to keep.
- Pulling on a device for the first time always asks before overwriting.
- The secret key is stored only on the device. It is never included in exports, share links or pushed data, and the server also strips it.

**Both the page and `sync.php` must be served over HTTPS.**
- `sync.php` refuses plain-HTTP requests, except from localhost for testing.
- Browsers block an HTTPS page from calling an HTTP endpoint.
- Over plain HTTP your secret key would travel unencrypted.

`sync.php` validates every request:
- It needs the Bearer secret, compared in constant time.
- It accepts only JSON bodies of 1 MB or less that look like tracker data.
- It re-encodes the data before writing it atomically, and never echoes the raw input.

## 4. Where your data lives (important)

- Everything is saved automatically in the browser's **localStorage** under the key `fde-tracker-v1`. The "Saved" indicator in the top bar confirms each save.
- localStorage is **separate per browser, per device and per site address**:
  - Chrome on your laptop, Firefox on the same laptop, and Safari on your phone each have their own copy.
  - `file:///…/index.html` and `https://you.github.io/…` are also separate.
- Clearing site data, using a private window, or some browser cleanup tools can **delete it**. Safari may also remove storage for sites you haven't visited in a while.
- If storage is blocked, the app still works but shows "Storage blocked", and changes are lost when the tab closes.

To move or protect your data:
1. **Export JSON** (Settings): downloads `fde-tracker-backup-YYYY-MM-DD.json`. Do this weekly. **Import JSON** checks the file and asks before overwriting.
2. **Share link**: packs all data into the URL (gzip + base64url after `#data=`). Open it on the other device and confirm.
   - The data sits after the `#`, so it is not sent to the web server.
   - Anyone who has the link can read your data, so send it only to yourself.
   - Long links (more than about 8,000 characters) may get cut off by chat or email apps.
3. **Cloud sync** via Vercel (section 3) or `sync.php` (section 3b).

## 5. Editing the app

`index.html` is split into numbered, commented sections:
- CSS: 1 design tokens (all colors), 2 layout, 3 components, 4 print stylesheet.
- JS: 5 seed data, 6 helpers, 7 state/validation/storage, 8 derived values, 9 navigation, 10–16 one render function per tab, 17 export/import, 18 share link, 19 cloud sync, 20 print, 21 startup.

- To change the plan, edit `PLAN` (weekly plan), `TOPICS` / `TOPIC_MONTHS` / `SKIP_FOR_NOW` (roadmap.sh-style topic map), `RELEASE_CHECKLIST`, `READINESS`, `CP_M2` / `CP_M4` in section 5. If you rename a topic or sub-topic, its saved status resets, because statuses are stored by name. Seed data applies only on first load or after **Reset all data**.
- If you change the data shape, increase `SCHEMA_VERSION` and add a step in `migrate()`.
- User text is always inserted with `textContent` through the `h()` helper. Never use `innerHTML` with user data.
# ai-engineer-roadmap
