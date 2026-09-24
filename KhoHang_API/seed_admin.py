"""Create an admin account for a local test database using prompted credentials."""

from getpass import getpass
import sys
from pathlib import Path

# Add app directory to path
sys.path.insert(0, str(Path(__file__).parent))

import uuid
from datetime import datetime, timezone
import bcrypt


def hash_password_simple(password: str) -> str:
    """Simple bcrypt hash"""
    return bcrypt.hashpw(password.encode('utf-8'), bcrypt.gensalt()).decode('utf-8')


def seed_admin():
    if not sys.stdin.isatty():
        raise RuntimeError("Admin seeding requires an interactive terminal")

    email = input("Admin email: ").strip()
    password = getpass("Admin password (12-100 characters): ")
    passkey = getpass("Admin passkey (8-50 characters): ")
    if "@" not in email or not 12 <= len(password) <= 100 or not 8 <= len(passkey) <= 50:
        raise ValueError("A valid email, password and passkey are required")

    from app.database import SessionLocal, UserModel

    db = SessionLocal()
    try:
        # Check if admin already exists
        existing_admin = db.query(UserModel).filter(UserModel.username == "admin").first()
        if existing_admin:
            print("✓ Admin user already exists")
            print(f"  Username: admin")
            print(f"  Email: {existing_admin.email}")
            print(f"  Role: {existing_admin.role}")
            return
        
        admin_user = UserModel(
            id=str(uuid.uuid4()),
            username="admin",
            email=email,
            display_name="Administrator",
            password_hash=hash_password_simple(password),
            passkey_hash=hash_password_simple(passkey),
            role="admin",
            is_verified=True,
            created_at=datetime.now(timezone.utc),
            updated_at=datetime.now(timezone.utc)
        )
        
        db.add(admin_user)
        db.commit()
        
        print("✓ Admin user created successfully!")
        print(f"  Username: admin")
        print(f"  Email: {email}")
        print(f"  Role: admin")
        print(f"  ID: {admin_user.id}")
        
    except Exception as e:
        print(f"✗ Error creating admin user: {e}")
        db.rollback()
    finally:
        db.close()


if __name__ == "__main__":
    print("=" * 50)
    print("Seeding admin user...")
    print("=" * 50)
    seed_admin()
    print("=" * 50)
