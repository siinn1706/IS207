<div align="center">

![Repo Badge](https://img.shields.io/badge/NT106_Quan_Ly_Kho_NHOM12-16a34a?style=for-the-badge&logo=github&logoColor=white)

# 📦 Ứng dụng Quản lý Nhập Xuất Kho
### NT106_QuanLyKho_Nhom12

**IS207:** Bản chụp từ [NT106_QuanLyKho tại commit `7499d98`](https://github.com/siinn1706/NT106_QuanLyKho/commit/7499d982e49b1b64c5848d568019c33a82467bbc), đã bỏ dữ liệu upload và thông tin admin mẫu khỏi bản công khai. Backend hiện vẫn là FastAPI; [kế hoạch chuyển sang PHP](plans/260924-0634-migrate-khohang-api-to-php/plan.md) chưa được triển khai.

[![License](https://img.shields.io/badge/License-MIT-green?style=flat-square&logo=opensourceinitiative&logoColor=white)](LICENSE)
![Python](https://img.shields.io/badge/Backend-FastAPI_%7C_Python-3776AB?style=flat-square&logo=python&logoColor=white)
![React](https://img.shields.io/badge/Frontend-React_%7C_Tauri-61DAFB?style=flat-square&logo=react&logoColor=black)
![Status](https://img.shields.io/badge/Status-Active-brightgreen?style=flat-square)

<p><b>Mục tiêu:</b> Ứng dụng quản lý tồn kho hiệu quả với nhập/xuất hàng, tính toán COGS theo FIFO, báo cáo lợi nhuận chính xác và trợ lý AI tích hợp.</p>

</div>

---

## 🔸 Tóm tắt nhanh
- **Hệ thống 2 thành phần**: Backend FastAPI + Frontend React/Tauri (Desktop + Web Dev Server)
- **Xác thực**: JWT + OTP email, Passkey cho thao tác nguy hiểm, RBAC (Admin/Manager/Staff)
- **Quản lý kho**: Sản phẩm, nhà cung cấp, nhiều kho; phiếu nhập/xuất, hủy phiếu, cập nhật tồn
- **Báo cáo & FIFO**: COGS tự động bằng FIFO, lợi nhuận chính xác, Excel/PDF export
- **Tính năng AI**: Chatbot Gemini, Chat realtime WebSocket, Upload tệp
- **Tìm kiếm**: Global search, gợi ý nhanh, tĩnh phục vụ uploads

## 🟢 Nhóm phát triển (NT106 - UIT)
| STT | Họ và tên | MSSV | Vai trò |
|:---:|:---:|:---:|:---|
| 1 | **Hoàng Xuân Minh Trí** | 24521829 | Fullstack / Leader |
| 2 | **Trương Minh Thái** | 24521599 | Frontend Dev |
| 3 | **Nguyễn Võ Minh Trí** | 24521840 | Backend Dev |
| 4 | **Nguyễn Văn Nam** | 24521120 | Fullstack |

## ✨ Tính năng chính

### 📦 Quản lý hàng hóa
- Sản phẩm với danh mục, giá mua/bán, hình ảnh
- Nhà cung cấp liên kết với sản phẩm
- Nhiều kho với tồn riêng biệt
- Cảnh báo tồn thấp, tìm kiếm nâng cao

### 📑 Nghiệp vụ kho
- Phiếu nhập hàng (Purchase Orders)
- Phiếu xuất hàng (Sales Orders) 
- Hủy phiếu với log chi tiết
- Cập nhật tồn kho tức thời
- Lịch sử giao dịch đầy đủ

### 💰 Báo cáo & FIFO
- **FIFO Inventory Valuation**: Tính COGS tự động bằng FIFO
- Lợi nhuận = Doanh số - COGS (chính xác)
- Export báo cáo Excel/PDF
- Phân tích lợi nhuận theo sản phẩm/kho
- Giá trị tồn kho tính toán FIFO

### 🔒 Bảo mật & Phân quyền
- JWT token với OTP email
- Passkey cho xóa/hủy
- RBAC 3 cấp: Admin/Manager/Staff
- Lịch sử đăng nhập, hoạt động

### 🤖 AI & Chat
- Chatbot Gemini hỗ trợ hướng dẫn sử dụng
- Chat realtime qua WebSocket
- Upload tệp trong chat
- Thông báo tức thời

### 🔍 Tìm kiếm & UX
- Global search toàn hệ thống
- Gợi ý nhanh, autocomplete
- Dark/Light theme, customize chat wallpaper
- Responsive Desktop/Web

## 🏗️ Kiến trúc & thư mục
```tree
NT106_QuanLyKho/
├─ KhoHang_API/          # Backend FastAPI, SQLite/Postgres, AI client
│  ├─ app/               # Mã nguồn chính (auth, inventory, export, chat...)
│  ├─ data/              # Thư mục dữ liệu, uploads (tự tạo nếu chưa có)
│  └─ .env.example       # Cấu hình backend mẫu
├─ UI_Desktop/           # Frontend React + Tauri (desktop), Vite + Tailwind
│  ├─ src/               # UI, features, hooks, services
│  ├─ src-tauri/         # Cấu hình Tauri/Rust
│  └─ .env.example       # Cấu hình frontend mẫu
├─ start_app.bat         # Chạy backend + frontend cùng lúc (Windows)
├─ start_backend.bat     # Chạy FastAPI dev server
├─ start_frontend.bat    # Chạy Tauri dev
├─ start_client1.bat     # Chạy web dev tại port 5173
├─ start_client2.bat     # Chạy web dev tại port 5174
└─ README.md
```

## 🛠️ Yêu cầu
- Python 3.10+ (khuyến nghị 3.11), pip, venv
- Node.js 18+ và npm
- Rust + Cargo (bắt buộc nếu chạy Tauri desktop)
- SMTP (Gmail) cho OTP email; khóa Gemini API cho chatbot (tùy chọn)
- SQLite mặc định; có thể chuyển Postgres qua `DATABASE_URL`

## 🔧 Cấu hình môi trường
**Backend**: sao chép [KhoHang_API/.env.example](KhoHang_API/.env.example) thành `.env` và chỉnh:
- `JWT_SECRET`, `JWT_ALGORITHM`, `JWT_EXP_DAYS`
- `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `FROM_EMAIL`
- `DATABASE_URL` (bỏ trống để dùng SQLite `data.db` mặc định)
- `GEMINI_API_KEY` (tùy chọn, bật chatbot)

**Frontend**: sao chép [UI_Desktop/.env.example](UI_Desktop/.env.example) thành `.env` và chỉnh:
- `VITE_API_BASE_URL=http://localhost:8000` (hoặc URL deployment)

## 🚀 Chạy nhanh (Windows)

### 1️⃣ Chạy toàn bộ (Simple)
```bash
start_app.bat
```
- Tự cài đặt dependencies
- Khởi động Backend FastAPI (port 8000)
- Khởi động Frontend Tauri Desktop

### 2️⃣ Chạy Backend & Frontend riêng biệt
```bash
# Terminal 1: Backend
start_backend.bat

# Terminal 2: Frontend
start_frontend.bat
```

### 3️⃣ Chạy Web Dev Server (thay Tauri)
```bash
# Terminal 1: Backend
start_backend.bat

# Terminal 2: Web Client (port 5173)
start_client1.bat

# Hoặc second client (port 5174)
start_client2.bat
```

## 🧩 Chạy thủ công (không dùng .bat)

### Backend (FastAPI)
```bash
cd KhoHang_API

# Setup environment
python -m venv .venv
.venv\Scripts\activate  # Windows
# source .venv/bin/activate  # Linux/Mac

# Install & run
pip install -r requirements.txt
uvicorn app.main:app --reload
```
- API docs: http://localhost:8000/docs
- ReDoc: http://localhost:8000/redoc

### Frontend (React + Tauri)
```bash
cd UI_Desktop

# Install dependencies
npm install

# Desktop (Tauri)
npm run tauri dev

# Hoặc Web Dev Server
npm run dev -- --port 5173 --host
```

## 🔧 Cấu hình

### Backend (.env)
```bash
cd KhoHang_API
cp .env.example .env
# Chỉnh sửa:
# - JWT_SECRET, JWT_ALGORITHM, JWT_EXP_DAYS
# - SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, FROM_EMAIL (OTP)
# - DATABASE_URL (SQLite mặc định, hoặc Postgres)
# - GEMINI_API_KEY (AI chatbot - optional)
```

### Frontend (.env)
```bash
cd UI_Desktop
cp .env.example .env
# Chỉnh sửa:
# - VITE_API_BASE_URL=http://localhost:8000
```

## 💾 Quản lý dữ liệu

### Cơ sở dữ liệu
- **SQLite** (mặc định): `KhoHang_API/data/data.db`
- **PostgreSQL**: Đặt `DATABASE_URL` trong `.env`
- Migration tự động khi chạy app

### Uploads & Files
- Đường dẫn: `KhoHang_API/data/uploads/`
- Loại: avatars, chat_files, chatbot, logos, rt_files
- Phục vụ tĩnh qua `/uploads/*`
- Thư mục tạo tự động lần đầu chạy

## 🌿 Branching Strategy
- `main`: Production ready
- `dev`: Development branch
- `feat/<tên-tính-năng>`: Feature branches
- Tạo PR để merge vào `dev` trước

## 📝 Script Utilities

### Backend
- `seed_admin.py`: Tạo tài khoản Admin thử nghiệm bằng thông tin nhập tương tác; không có mật khẩu mặc định
- `reset_database.py`: Reset DB (development only)
- `test_inventory_valuation.py`: Test FIFO logic
- `test_export.py`: Test export Excel/PDF
- `check_db_schema.py`: Kiểm tra schema DB
- Các script migrate schema nếu cần

### Frontend
- `npm run build`: Build production
- `npm run preview`: Preview build
- `npm run lint`: Code linting

## 🐛 Troubleshooting

| Vấn đề | Giải pháp |
|--------|----------|
| `ModuleNotFoundError: No module named 'app'` | Chạy từ thư mục `KhoHang_API` |
| `CORS error` | Kiểm tra `VITE_API_BASE_URL` trong `.env` frontend |
| `SQLite database is locked` | Đóng terminal khác chạy backend, restart |
| `Port 8000/5173 already in use` | Đổi port hoặc kill process đang dùng |
| `Missing .env file` | Copy `.env.example` → `.env` |

## 📚 Tài liệu chi tiết

### Backend Architecture
- **Models**: Database schemas (Product, Warehouse, StockIn/Out, User, etc)
- **Routes**: Auth, User, Inventory, Export, Chatbot, Chat realtime
- **Services**: Inventory valuation (FIFO), Export, Search, AI client
- **RBAC**: Role-based access control middleware
- **Security**: JWT, OTP, Passkey validation

### Frontend Architecture
- **Features**: Auth, Dashboard, Items, Stock, Warehouses, Suppliers, Reports
- **Components**: Layout, Chat, Theme, UI utilities
- **State**: Zustand stores (auth, chat, notifications, theme)
- **Services**: API client, WebSocket chat, Authentication
- **Themes**: Customizable chat wallpapers, dark/light modes

## 🎓 FIFO Inventory Valuation

Hệ thống tính **COGS (Cost of Goods Sold)** theo FIFO:
```
Nhập 10x @ 100k, nhập 10x @ 150k
Xuất 15x @ 200k
→ COGS = 10×100k + 5×150k = 1,750k
→ Lợi nhuận = (15×200k) - 1,750k = 1,250k ✓
```

Tất cả báo cáo lợi nhuận đều dựa trên FIFO COGS chính xác.

## 🤝 Đóng góp
- Fork dự án
- Tạo nhánh `feat/<tên-tính-năng>`
- Commit changes với message rõ ràng
- Push & tạo Pull Request
- Chờ review & merge

## 📄 License
MIT License - Tự do sử dụng và phân phối

---

<div align="center">
  <b>Dự án môn học NT106 - UIT</b><br>
  <i>Ứng dụng Quản lý Nhập Xuất Kho</i><br>
  <b>2025</b>
</div>
