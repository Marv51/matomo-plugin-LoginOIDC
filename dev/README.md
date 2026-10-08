# Local test environment

Matomo 5 + MariaDB in Docker, with this plugin mounted read-only and configured
against a real OpenID Connect provider.

```bash
cp dev/.env.example dev/.env   # fill in discovery URL, client id and secret
dev/setup.sh
```

Then open http://localhost:8080 (superuser `admin` / `matomo-dev-password`).
The provider must allow the callback URL that `setup.sh` prints for your client.
Code changes are picked up immediately, re-run `setup.sh` after changing `.env`.

`setup.sh` enables sign-up, "Disable password confirmation" and "Disable password login for
linked users", so all of them can be tested. Emails Matomo sends end up in Mailpit at
http://localhost:8025. Stop with `docker compose -f dev/docker-compose.yml down` (`-v` wipes all data).

## Test plan

Works with a single provider account. Run the steps in order.

1. **Sign-up / sign-in:** on the login page use the OIDC button. You come back logged in
   as a new user named after your email. The token exchange only succeeds if PKCE works.
2. **Password confirmation skipped after OIDC sign-in:** *Personal → Settings*, change the
   email. No password prompt (within 30 minutes of signing in).
3. **Logout:** the provider ends its session too and sends you back to Matomo's login page.
4. **Foreign OIDC round trip doesn't skip confirmation:** log in as `admin` with the password.
   In *Administration → System → General settings → LoginOIDC*, uncheck
   "Disable direct login URL". Open `/index.php?module=LoginOIDC&action=signin` and sign in
   at the provider. Expected: "already linked to another account". Then change the admin's
   email in *Personal → Settings*: the password **is** still required.
5. **Free the provider account:** *Administration → System → Users*, delete the user from step 1.
6. **Linking without confirmation is refused:** open `/index.php?module=LoginOIDC&action=signin`
   again. Expected: "Linking requires a recent password confirmation…" and no link in
   *Personal → Security*.
7. **Linking:** *Personal → Security → Link*. Matomo asks for the admin password first, then
   sends you to the provider. Afterwards the account shows as linked.
8. **Re-authentication via OIDC:** *Personal → Security → Create new token*. The password
   confirmation page shows the OIDC button. Using it continues to the token page.
9. **Sign-in of the linked account:** log out, use the OIDC button, you are logged in as `admin`.
10. **No password login for linked accounts:** log out and sign in as `admin` with the password.
    Expected: "Password login is disabled for this account". "Lost your password?" for `admin`
    shows the usual confirmation message, but no email arrives in Mailpit.

Optional: 30 minutes after the OIDC sign-in, step 2 asks for a password again.

To get password login for `admin` back, unlink it in *Personal → Security* (signed in via OIDC)
or uncheck "Disable password login for linked users".
