# Security

Please do not open public issues for security vulnerabilities.

If you find a security issue, contact the maintainer privately through the contact options on https://rehmeier.es/.

The audit endpoint blocks localhost, private/reserved IP ranges and redirects to private targets to reduce SSRF risk. This is a lightweight public project, so deployments should still apply normal reverse-proxy rate limits and request limits.
