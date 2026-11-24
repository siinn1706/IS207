"""
Builds a synthetic SQLite fixture with the legacy schema (database/schema/legacy.sql,
captured from SQLAlchemy create_all) and three users. No production data involved.
  python seed_fixture.py <path/to/data.db>
Requires passlib+bcrypt (same hashing library the FastAPI backend used).
"""
import os
import sqlite3
import sys
from datetime import datetime, timezone

from passlib.context import CryptContext

ctx = CryptContext(schemes=["bcrypt"], deprecated="auto")
here = os.path.dirname(os.path.abspath(__file__))
schema = open(os.path.join(here, "..", "..", "database", "schema", "legacy.sql"), encoding="utf-8").read()

db = sys.argv[1]
os.makedirs(os.path.dirname(os.path.abspath(db)), exist_ok=True)
if os.path.exists(db):
    os.remove(db)
con = sqlite3.connect(db)
con.executescript(schema)
now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S.%f")
users = [
    ("u-admin", "admin", "admin@example.com", "Quản trị", ctx.hash("admin123"), ctx.hash("246810"), "admin", 1),
    ("u-staff", "staff", "staff@example.com", None, ctx.hash("staff123"), None, "staff", 1),
    ("u-pending", "pending", "pending@example.com", None, ctx.hash("secret123"), None, "staff", 0),
]
for u in users:
    con.execute(
        "INSERT INTO users (id, username, email, display_name, password_hash, passkey_hash, role, is_verified, created_at, updated_at)"
        " VALUES (?,?,?,?,?,?,?,?,?,?)", (*u, now, now))
con.commit()
print("seeded", db)
