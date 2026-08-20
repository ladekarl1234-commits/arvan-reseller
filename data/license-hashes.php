<?php
/**
 * Plugin Access Token allowlist — bcrypt hashes ONLY (ADR-0009).
 * Plaintext tokens are distributed out-of-band; demo tokens for judges are
 * documented in DEVELOPMENT.md. Never commit a plaintext production token.
 */
return [
    '$2y$12$M4wTsNft0k9bVhcbWGZ4D.X8yk6JYQIPc1WdrtDS5SzGxTFM9TdMW',
    '$2y$12$DeRZRUuFDKBGgAhPAMWmUOMpiPYd2FLnrOqapmp7NWKDbDOhAuV3.',
    '$2y$12$ufKinLyDHOC3aUV8e0OJN.3pzi14KhdHflg.Ak5GUZIfvrcQmaRiW',
];
