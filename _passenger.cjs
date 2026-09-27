try {
  require('./dist/server.js');
} catch (error) {
  console.error('Failed to start multilingual-seo-audit:', error);
  process.exit(1);
}
