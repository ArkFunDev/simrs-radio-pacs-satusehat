# Security policy

This software processes health data. If you find a vulnerability, **do not open a public
issue**. Use GitHub's "Report a vulnerability" (Security tab) or contact the maintainer
privately through the GitHub profile.

Please never attach real patient data, credentials or hospital identifiers to any report.

Operational guidance: run behind HTTPS on a private network/VPN, keep `config.local.php`
at `chmod 600`, keep `TOKEN_CACHE_DIR` outside the web root, rotate any credential that
has ever been committed, and use SatuSehat STAGING until fully validated.
