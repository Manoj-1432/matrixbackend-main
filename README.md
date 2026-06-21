# Matrix Backend API

A Laravel-based REST API backend for the Matrix platform, providing admin authentication and management endpoints.

---

## 🚀 Tech Stack

- **Framework**: Laravel 13
- **Auth**: Laravel Sanctum (token-based)
- **Database**: MySQL
- **PHP**: 8.3+ (required by Laravel 13)

---

## ⚙️ Setup & Installation

```bash
# Clone the repo
git clone https://github.com/workatmo/matrixbackend.git
cd matrixbackend

# Install dependencies
composer install

# Copy env file and set your values
cp .env.example .env

# Generate app key
php artisan key:generate

# Run migrations
php artisan migrate

# Start the dev server
php artisan serve
```

---

## 🔐 Admin Login API

### `POST /api/admin/login`

Authenticate as an admin and receive a Bearer token.

**Request Body:**

```json
{
  "email": "admin@example.com",
  "password": "your_password"
}
```

**Success Response `200`:**

```json
{
  "token": "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "token_type": "Bearer"
}
```

**Error Response `401`:**

```json
{
  "message": "Invalid credentials."
}
```

**Error Response `422` (Validation):**

```json
{
  "message": "Validation error.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

> Use the returned token as `Authorization: Bearer <token>` in all subsequent protected requests.

---

## 📋 Admin API Endpoints

All routes below require `Authorization: Bearer <token>` header.

| Method   | Endpoint                  | Description           |
|----------|---------------------------|-----------------------|
| `POST`   | `/api/admin/login`        | Admin login (public)  |
| `GET`    | `/api/admin/profile`      | Get admin profile     |
| `GET`    | `/api/admin/users`        | List all users        |
| `POST`   | `/api/admin/users`        | Create a user         |
| `PUT`    | `/api/admin/users/{id}`   | Update a user         |
| `DELETE` | `/api/admin/users/{id}`   | Delete a user         |
| `GET`    | `/api/admin/vehicles`     | List all vehicles     |
| `POST`   | `/api/admin/vehicles`     | Create a vehicle      |
| `PUT`    | `/api/admin/vehicles/{id}`| Update a vehicle      |
| `DELETE` | `/api/admin/vehicles/{id}`| Delete a vehicle      |
| `GET`    | `/api/admin/orders`       | List all orders       |
| `POST`   | `/api/admin/orders`       | Create an order       |
| `PUT`    | `/api/admin/orders/{id}`  | Update an order       |
| `DELETE` | `/api/admin/orders/{id}`  | Delete an order       |
| `GET`    | `/api/admin/tyres`        | List all tyres        |
| `POST`   | `/api/admin/tyres`        | Create a tyre         |
| `PUT`    | `/api/admin/tyres/{id}`   | Update a tyre         |
| `DELETE` | `/api/admin/tyres/{id}`   | Delete a tyre         |

---

## 🗄️ Database Configuration

Update your `.env` file with your MySQL credentials:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=matrix_db
DB_USERNAME=root
DB_PASSWORD=
```

---

## 🖥️ Hostinger Deployment (Composer)

`composer.json` pins **`config.platform.php` to `8.3.0`** so `composer.lock` stays compatible with **PHP 8.3** (typical on shared hosting). Symfony **8.x** requires **PHP ≥ 8.4**; the lock file therefore resolves **Symfony 7.4** for this project.

If deployment still fails:

1. In hPanel, set **PHP 8.3+** for the site and ensure the **CLI / deploy** step uses the same version.
2. Commit and push **`composer.json`** and **`composer.lock`** together after any dependency change.
3. Prefer **`composer install --no-dev --optimize-autoloader`** on the server; avoid a full **`composer update`** unless you intend to change versions locally and re-lock.

---

## 📄 License

This project is private software owned by [Workatmo](https://github.com/workatmo).
