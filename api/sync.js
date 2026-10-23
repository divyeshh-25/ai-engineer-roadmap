/**
 * FDE Tracker - cloud sync as a Vercel serverless function (same contract as sync.php).
 *
 *   GET  /api/sync -> returns the stored JSON (404 if nothing stored yet)
 *   POST /api/sync -> validates and stores the JSON body (max 1 MB)
 *
 * Every request needs:  Authorization: Bearer <FDE_SYNC_SECRET>
 *
 * Environment variables (Vercel → Project → Settings → Environment Variables):
 *   FDE_SYNC_SECRET   required, 24+ random characters
 *   KV_REST_API_URL + KV_REST_API_TOKEN            added automatically when you connect
 *   (or UPSTASH_REDIS_REST_URL + UPSTASH_REDIS_REST_TOKEN)  Upstash Redis to the project
 *   FDE_SYNC_ORIGIN   optional, only if the page is served from a different site
 */
const crypto = require('crypto');

const MAX_BYTES = 1024 * 1024; // 1 MB
const STORE_KEY = 'fde-tracker:data';

// ---------------------------------------------------------------- helpers
function send(res, status, body) {
  res.statusCode = status;
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.end(typeof body === 'string' ? body : JSON.stringify(body));
}

// Constant-time comparison (hashing first makes lengths equal).
function safeEqual(a, b) {
  const ha = crypto.createHash('sha256').update(String(a)).digest();
  const hb = crypto.createHash('sha256').update(String(b)).digest();
  return crypto.timingSafeEqual(ha, hb);
}

// Minimal Upstash Redis REST client: POST ["CMD", ...args] -> { result }
async function redis(command) {
  const url = process.env.KV_REST_API_URL || process.env.UPSTASH_REDIS_REST_URL;
  const token = process.env.KV_REST_API_TOKEN || process.env.UPSTASH_REDIS_REST_TOKEN;
  if (!url || !token) throw new Error('Storage not configured: connect Upstash Redis to this project');
  const r = await fetch(url, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
    body: JSON.stringify(command)
  });
  if (!r.ok) throw new Error(`Storage error ${r.status}`);
  return (await r.json()).result;
}

// Vercel pre-parses JSON bodies; the getter throws on invalid JSON.
function readJsonBody(req) {
  try {
    const b = req.body;
    if (Buffer.isBuffer(b)) return JSON.parse(b.toString('utf8'));
    if (typeof b === 'string') return JSON.parse(b);
    return b;
  } catch (e) { return undefined; }
}

// ---------------------------------------------------------------- handler
module.exports = async (req, res) => {
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('Cache-Control', 'no-store');
  res.setHeader('Referrer-Policy', 'no-referrer');
  res.setHeader('Vary', 'Origin');

  // CORS: same-site requests are always fine; another site only if it is FDE_SYNC_ORIGIN.
  const origin = req.headers.origin;
  if (origin) {
    const host = req.headers['x-forwarded-host'] || req.headers.host;
    const sameSite = origin === `https://${host}` || origin === `http://${host}`;
    const allowed = process.env.FDE_SYNC_ORIGIN;
    if (!sameSite && !(allowed && safeEqual(origin, allowed))) return send(res, 403, { error: 'Origin not allowed' });
    if (!sameSite) {
      res.setHeader('Access-Control-Allow-Origin', allowed);
      res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
      res.setHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type');
      res.setHeader('Access-Control-Max-Age', '600');
    }
  }
  if (req.method === 'OPTIONS') { res.statusCode = 204; return res.end(); }

  const secret = process.env.FDE_SYNC_SECRET || '';
  if (secret.length < 24) return send(res, 500, { error: 'Server not configured: set FDE_SYNC_SECRET (24+ characters)' });

  const m = /^Bearer\s+(\S+)$/i.exec((req.headers.authorization || '').trim());
  if (!m || !safeEqual(m[1], secret)) {
    await new Promise(r => setTimeout(r, 300 + Math.random() * 400)); // slow down guessing
    return send(res, 401, { error: 'Unauthorized' });
  }

  try {
    // ------------------------------------------------------------ GET = read
    if (req.method === 'GET') {
      const stored = await redis(['GET', STORE_KEY]);
      if (stored == null) return send(res, 404, { error: 'No data stored yet' });
      return send(res, 200, stored); // only ever JSON this function re-encoded itself
    }

    // ------------------------------------------------------------ POST = write
    if (req.method === 'POST') {
      if (!String(req.headers['content-type'] || '').toLowerCase().startsWith('application/json')) {
        return send(res, 415, { error: 'Content-Type must be application/json' });
      }
      if (Number(req.headers['content-length'] || 0) > MAX_BYTES) return send(res, 413, { error: 'Payload too large (max 1 MB)' });

      const data = readJsonBody(req);
      if (!data || typeof data !== 'object' || Array.isArray(data)) return send(res, 400, { error: 'Body is not valid JSON' });
      if (data.app !== 'fde-tracker' || !Number.isInteger(data.version) || typeof data.updatedAt !== 'string'
        || Number.isNaN(Date.parse(data.updatedAt)) || !Array.isArray(data.weeks)) {
        return send(res, 422, { error: 'JSON is not tracker data' });
      }
      delete data.sync; // never store sync secrets, even if a client sends them

      const clean = JSON.stringify(data); // re-encode instead of storing raw input
      if (Buffer.byteLength(clean) > MAX_BYTES) return send(res, 413, { error: 'Payload too large (max 1 MB)' });
      await redis(['SET', STORE_KEY, clean]);
      return send(res, 200, { ok: true, updatedAt: data.updatedAt, bytes: Buffer.byteLength(clean) });
    }

    res.setHeader('Allow', 'GET, POST, OPTIONS');
    return send(res, 405, { error: 'Method not allowed' });
  } catch (e) {
    console.error('sync error:', e.message); // message only, never the payload
    return send(res, 500, { error: 'Storage unavailable' });
  }
};
