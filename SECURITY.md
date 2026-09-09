# Security Policy

## Supported versions

`0.1.x` is currently beta software. Do not deploy it to production without testing on a staging MODX installation.

## Automated checks

Every push and pull request is checked by:

- PHP syntax lint on PHP 8.1, 8.2, 8.3 and 8.4;
- Composer validation/install;
- GitHub CodeQL for PHP and JavaScript/TypeScript using `security-extended` queries.

## Manual security checklist

Before a release we review at least:

- HTML/JavaScript output escaping and XSS sinks;
- CSRF protection on public write endpoints;
- SQL parameterization;
- subscription endpoint validation and request-size limits;
- accidental disclosure of VAPID private keys or raw exception details;
- URL handling in notification click/image/icon fields;
- Service Worker scope and HTTPS requirements;
- authorization of future Manager/admin actions.

## Reporting

Please open a private security report through GitHub Security Advisories when available. Do not publish working exploit details in a public issue before a fix is available.
