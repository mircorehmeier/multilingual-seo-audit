async function main() {
  await import('./dist/server.js');
}

main().catch((error) => {
  console.error('Failed to start multilingual-seo-audit:', error);
  process.exit(1);
});
