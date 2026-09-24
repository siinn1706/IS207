# Admin Account for Local Testing

No default admin credentials are shipped with this repository. Create an admin
only in a local test database. Do not use the seeding script against a shared
or production database.

Run from an interactive terminal:

```text
cd KhoHang_API
python seed_admin.py
```

Enter an email, a unique password, and a distinct passkey when prompted. The
script does not print either secret. It keeps the username `admin` and role
`admin`, and refuses to overwrite an existing account.
