import express from 'express';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { auditSite } from './audit.js';

const app = express();
const port = Number(process.env.PORT || 3000);
const __dirname = path.dirname(fileURLToPath(import.meta.url));
const publicDir = path.resolve(__dirname, '../public');

app.disable('x-powered-by');
app.use(express.json({ limit: '32kb' }));
app.use(express.static(publicDir, { extensions: ['html'] }));

app.get('/api/health', (_req, res) => {
  res.json({ ok: true, version: '0.1.0' });
});

app.post('/api/audit', async (req, res) => {
  const url = typeof req.body?.url === 'string' ? req.body.url.trim() : '';
  const maxPages = Number(req.body?.maxPages ?? 25);
  if (!url) return res.status(400).json({ error: 'A URL is required.' });

  try {
    const result = await auditSite(url, maxPages);
    return res.json(result);
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Audit failed.';
    return res.status(400).json({ error: message });
  }
});

app.listen(port, () => {
  console.log(`Multilingual SEO Audit running on http://localhost:${port}`);
});
