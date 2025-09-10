[فارسی](./README.fa.md)

# AVCPanel Bot 🚀

[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.0-blue)](https://php.net)
[![GitHub license](https://img.shields.io/github/license/KimiVerse/AVCPanel)](https://github.com/KimiVerse/AVCPanel/blob/main/LICENSE)
[![GitHub stars](https://img.shields.io/github/stars/KimiVerse/AVCPanel)](https://github.com/KimiVerse/AVCPanel/stargazers)
[![GitHub issues](https://img.shields.io/github/issues/KimiVerse/AVCPanel)](https://github.com/KimiVerse/AVCPanel/issues)

An advanced and powerful Telegram bot for managing VPN panel users, specifically designed for the **Blitz Panel**. This bot utilizes PHP and PDO for secure communication with a MySQL database, offering extensive features for both administrators and users.

## ⚠️ Important Prerequisite

To use this bot, the **Blitz Panel** must be installed and running. This bot acts as a user management interface for it.

- **Blitz Panel Repository:** [https://github.com/ReturnFI/Blitz](https://github.com/ReturnFI/Blitz)  
  *Don't forget to support the panel's developer.*

## ✨ Key Features

- **User Management:**
  - Easy and fast user registration.
  - Wallet balance and referral system management.
  - View and manage purchased services.

- **Powerful Admin Panel:**
  - Full control over panels and servers.
  - Define and manage sales plans.
  - Ticketing system for user support.
  - View detailed sales and user statistics.

- **Payment System:**
  - Supports card-to-card payments with an auto-confirmation system.
  - Direct purchase using wallet balance.

- **Security:**
  - Encryption of sensitive user data.
  - Option to enforce membership in a Telegram channel.
  - Secure communication with the API and database.

- **Automated Tasks (Cron Jobs):**
  - Automatic removal of expired services.
  - Periodic renewal reminders.

## 🛠️ Tech Stack

- **Programming Language:** PHP 7.0 or higher
- **PHP Extensions:** cURL, PDO, OpenSSL
- **Database:** MySQL (with `utf8mb4` encoding for full language support)
- **Dependencies:** `jdf.php` for Jalali date management.
- **Interface:** Telegram Bot API

## 🚀 Quick Start

For a comprehensive installation guide, please read the [DEPLOYMENT.md](DEPLOYMENT.md) file.

1.  **Clone the repository:**
    ```bash
    git clone https://github.com/KimiVerse/AVCPanel.git
    ```
2.  Run `table.php` in your database to create the necessary tables.
3.  Create a copy of `setting/config.example.php` and rename it to `setting/config.php`.
4.  Fill in your bot token, database credentials, and other settings in `setting/config.php`.
5.  Upload the project to a server that supports PHP and MySQL.
6.  Set your Telegram bot's webhook to point to the `bot.php` file.

## 🤝 Contributing

We welcome contributions. You can fork the project, apply your changes, and submit a pull request. Please adhere to our [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).

## 📄 License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for more details.

---
**Developer:** Kimiya | **GitHub:** [KimiVerse](https://github.com/KimiVerse/AVCPanel) | **Admin Contact:** [t.me/amirmasoud_rsli](https://t.me/amirmasoud_rsli)

*Note: Any copying or modification without permission is prohibited.*

**In memory of Mehdi 🖤**