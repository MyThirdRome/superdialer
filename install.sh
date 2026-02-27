#!/bin/bash
# SuperDialer Installer
# Installs FreeSWITCH + Web Interface + Database

set -e

# --- CONFIGURATION ---
DB_PATH="/var/www/db/autodialer.sqlite"
WEB_ROOT="/var/www/html/autodialer"
REPO_URL="https://github.com/MyThirdRome/superdialer.git"
# ---------------------

echo "Starting SuperDialer Installation..."

# Step 1: Update system
apt update && apt upgrade -y

# Step 2: Install build dependencies (FreeSWITCH) + Web Server (Apache/PHP)
apt install -y build-essential cmake automake autoconf libtool pkg-config \
libssl-dev zlib1g-dev libdb-dev unixodbc-dev libncurses5-dev libexpat1-dev \
libgdbm-dev bison erlang-dev libtpl-dev libtiff5-dev uuid-dev libpcre3-dev \
libxml2-dev libcurl4-openssl-dev libopus-dev libsndfile1-dev libshout3-dev \
libmpg123-dev libmp3lame-dev libedit-dev yasm nasm libvpx-dev \
libavformat-dev libswscale-dev libpq-dev \
libsqlite3-dev libldns-dev libspeex-dev libspeexdsp-dev \
liblua5.2-dev python3-dev default-libmysqlclient-dev \
libsofia-sip-ua-dev libspandsp-dev git \
apache2 php libapache2-mod-php php-sqlite3 php-curl php-xml php-mbstring sqlite3

# Step 3: Build spandsp3 from source
cd /usr/local/src
if [ ! -d "spandsp" ]; then
    git clone https://github.com/freeswitch/spandsp.git 
    cd spandsp
    ./bootstrap.sh
    ./configure
    make -j$(nproc)
    make install
    ldconfig
fi

# Step 4: Build sofia-sip from source
cd /usr/local/src
if [ ! -d "sofia-sip" ]; then
    git clone https://github.com/freeswitch/sofia-sip.git 
    cd sofia-sip
    ./bootstrap.sh
    ./configure
    make -j$(nproc)
    make install
    ldconfig
fi

# Step 5: Build libks2 from source
cd /usr/local/src
if [ ! -d "libks" ]; then
    git clone https://github.com/signalwire/libks.git 
    cd libks
    cmake . -DCMAKE_INSTALL_PREFIX=/usr/local
    make -j$(nproc)
    make install
    ldconfig
fi

# Step 6: Clone FreeSWITCH 1.10
cd /usr/local/src
if [ ! -d "freeswitch" ]; then
    git clone https://github.com/signalwire/freeswitch.git -b v1.10 --depth=1
    cd freeswitch

    # Step 7: Bootstrap
    ./bootstrap.sh -j

    # Step 8: Disable problematic modules
    sed -i 's/applications\/mod_signalwire/#applications\/mod_signalwire/' modules.conf
    sed -i 's|applications/mod_spandsp|#applications/mod_spandsp|' modules.conf

    # Step 9: Configure
    PKG_CONFIG_PATH=/usr/local/lib/pkgconfig ./configure -C --enable-core-pgsql-support CFLAGS="-O2"

    # Step 10: Compile
    make -j$(nproc)

    # Step 11: Install
    make install

    # Step 12: Install sounds and MOH
    make hd-sounds-install hd-moh-install

    # Step 13: Install default config
    make samples
fi

# Step 14: Add FreeSWITCH to PATH via symlinks
ln -sf /usr/local/freeswitch/bin/freeswitch /usr/local/bin/freeswitch
ln -sf /usr/local/freeswitch/bin/fs_cli /usr/local/bin/fs_cli

# Step 15: Add library path
if ! grep -q "/usr/local/lib" /etc/ld.so.conf.d/local.conf; then
    echo '/usr/local/lib' >> /etc/ld.so.conf.d/local.conf
fi
ldconfig

# Step 16: Create systemd service
cat > /etc/systemd/system/freeswitch.service << 'EOF'
[Unit]
Description=FreeSWITCH
After=network.target

[Service]
Type=forking
PIDFile=/usr/local/freeswitch/run/freeswitch.pid
ExecStart=/usr/local/freeswitch/bin/freeswitch -ncwait -nonat
ExecStop=/usr/local/freeswitch/bin/freeswitch -stop
Restart=always
RestartSec=5
LimitCORE=infinity
LimitNOFILE=100000
LimitNPROC=60000
LimitSTACK=240000

[Install]
WantedBy=multi-user.target
EOF

# Step 17: Enable and start FreeSWITCH
systemctl daemon-reload
systemctl enable freeswitch
systemctl start freeswitch

# --- WEB & APP INSTALLATION ---

echo "Installing Web Interface..."

# Clone Repo
rm -rf $WEB_ROOT
mkdir -p $WEB_ROOT
git clone $REPO_URL $WEB_ROOT

# Create Database Directory
mkdir -p $(dirname $DB_PATH)

# Initialize Database
if [ ! -f "$DB_PATH" ]; then
    echo "Creating Database..."
    sqlite3 $DB_PATH < $WEB_ROOT/schema.sql
    
    # Enable WAL Mode immediately (CRITICAL FIX)
    sqlite3 $DB_PATH "PRAGMA journal_mode=WAL;"
    
    # Create Performance Index (CRITICAL FIX)
    sqlite3 $DB_PATH "CREATE INDEX IF NOT EXISTS idx_camp_status ON campaign_numbers (campaign_id, status);"
    
    # Create Admin User (Default: admin/admin)
    php -r 'echo password_hash("admin", PASSWORD_DEFAULT);' > /tmp/admin_pass
    PASS_HASH=$(cat /tmp/admin_pass)
    
    sqlite3 $DB_PATH "INSERT INTO users (username, password) VALUES ('admin', '$PASS_HASH');"
fi

# Set Permissions
chown -R www-data:www-data /var/www/html
chown -R www-data:www-data /var/www/db
mkdir -p $WEB_ROOT/uploads/lists
mkdir -p $WEB_ROOT/uploads/ivrs
mkdir -p $WEB_ROOT/uploads/cids
chmod -R 775 $WEB_ROOT/uploads
chmod 775 $(dirname $DB_PATH)
chmod 664 $DB_PATH

# Configure PHP Limits (CRITICAL FIX)
sed -i 's/upload_max_filesize = .*/upload_max_filesize = 100M/' /etc/php/*/apache2/php.ini
sed -i 's/post_max_size = .*/post_max_size = 100M/' /etc/php/*/apache2/php.ini
sed -i 's/memory_limit = .*/memory_limit = 512M/' /etc/php/*/apache2/php.ini
sed -i 's/max_execution_time = .*/max_execution_time = 300/' /etc/php/*/apache2/php.ini

# Configure Apache
cat > /etc/apache2/sites-available/autodialer.conf <<EOF
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot $WEB_ROOT
    
    <Directory $WEB_ROOT>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

a2dissite 000-default.conf
a2ensite autodialer.conf
a2enmod rewrite
systemctl restart apache2

# Setup Log Files (CRITICAL FIX)
touch /var/log/fs_dialer.log /var/log/fs_monitor.log
chown www-data:www-data /var/log/fs_dialer.log /var/log/fs_monitor.log

# Setup Background Services (Dialer & Monitor)
# Dialer Service
cat > /etc/systemd/system/fs-dialer.service <<EOF
[Unit]
Description=FS Autodialer Engine
After=network.target freeswitch.service

[Service]
Type=simple
User=www-data
ExecStart=/usr/bin/php $WEB_ROOT/dialer.php
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

# Monitor Service
cat > /etc/systemd/system/fs-monitor.service <<EOF
[Unit]
Description=FS Autodialer Monitor
After=network.target freeswitch.service

[Service]
Type=simple
User=www-data
ExecStart=/usr/bin/php $WEB_ROOT/monitor.php
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable fs-dialer fs-monitor
systemctl start fs-dialer fs-monitor

# --- FINISH ---
IP_ADDR=$(hostname -I | awk '{print $1}')

echo ""
echo "========================================="
echo " SuperDialer Installation Complete!"
echo "========================================="
echo " Web Interface: http://$IP_ADDR/"
echo " Login:         admin"
echo " Password:      admin"
echo " Database:      SQLite (No password needed)"
echo " DB Path:       $DB_PATH"
echo "========================================="
