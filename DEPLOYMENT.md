# AVCPanel Installation Guide

This guide will walk you through the complete installation process for AVCPanel on a personal server.

## 1. Prerequisites

1.  **Server:** A Virtual Private Server (VPS) or a dedicated server.
2.  **Recommended Resources:** 2 GB RAM & 2 Core CPU.
3.  **Recommended OS:** Ubuntu 22.04.
4.  **Domain:** A domain name with its DNS managed by Cloudflare.

> **Note:** This guide is for installation on a personal server, which allows you to use any port. If you are using shared cPanel hosting, you will need to ask your hosting provider to open the required port for you.

---

## 2. Install aaPanel

We will use **aaPanel** as a lightweight and professional control panel to manage the server.

1.  Connect to your server via SSH and run the command corresponding to your operating system:

    ### Ubuntu (20.04 / 22.04) / Debian (11 / 12)
    ```bash
    apt-get update -y && apt-get install -y wget curl sudo && \
    wget -O install.sh http://www.aapanel.com/script/install-ubuntu_6.0_en.sh && \
    bash install.sh
    ```

    ### CentOS (7/8/9)
    ```bash
    yum install -y wget && \
    wget -O install.sh http://www.aapanel.com/script/install_7.0_en.sh && \
    bash install.sh
    ```

2.  During the installation, you will be asked: `Do you want to install aaPanel to the /www directory now?(y/n):` Type `y` and press Enter.

3.  After the installation is complete, your login credentials will be displayed. **Save this information in a secure place.**

---

## 3. Install Required Packages

1.  Log in to your aaPanel dashboard.
2.  Upon your first login, a window will pop up to recommend packages. Choose the **LNMP** stack and select the following versions:
    -   **Nginx:** `1.24`
    -   **MySQL:** `mariadb_10.11`
    -   **PHP:** `7.4`
    -   **Pure-FTPd:** `1.0.49`
    -   **PHPMyAdmin:** `4.9`
3.  Click **One-click install** and wait for all packages to be installed.

---

## 4. Add Website and SSL

1.  From the left sidebar, navigate to `Website`.
2.  Click `Add site`.
3.  Enter the subdomain that you have already pointed to your server's IP in Cloudflare.
4.  Ensure the PHP Version is set to `7.4` and click `Submit`.
5.  **Activate SSL:**
    -   Click on your domain name.
    -   From the left menu, select `SSL`.
    -   Go to the `Let's Encrypt` tab and check the `Select all` box.
    -   Click the green `Apply` button.
6.  After the certificate is issued, enable the **Force HTTPS** toggle.

---

## 5. Create a Database

1.  In aaPanel, go to `Database` from the left sidebar.
2.  Click `Add Database`.
3.  Enter a database name (e.g., `avcpanel`) and a strong password. **Save the password.**
4.  Click `Submit`.

---

## 6. Clone the Project

1.  Using the terminal, navigate to your website's root directory (e.g., `/www/wwwroot/your_domain.com`).
2.  Remove any existing files and run the cloning script:

    ```bash
    wget -O cloning.sh https://raw.githubusercontent.com/KimiVerse/AVCPanel/main/cloning.sh && chmod +x cloning.sh && sudo ./cloning.sh
    ```

---

## 7. Create a Telegram Bot & Configure

1.  Go to the [@BotFather](https://t.me/BotFather) on Telegram and create a new bot.
2.  Copy the bot's **Token**.
3.  On your server, edit the `config.php` file and replace the placeholder values with your own:
    -   Bot Token
    -   Database Password
    -   Owner Telegram ID
    -   Other database details

---

## 8. Set the Webhook

Finally, run the webhook script to connect your bot to the panel.

```bash
wget -O set_webhook.sh https://raw.githubusercontent.com/KimiVerse/AVCPanel/main/set_webhook.sh && chmod +x set_webhook.sh && ./set_webhook.sh && rm set_webhook.sh
```
---

Congratulations! The installation is now complete.