#!/bin/bash
set -e

mysql -u root -p"$MYSQL_ROOT_PASSWORD" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS \`finance_tracker_testing\`;
    GRANT ALL PRIVILEGES ON \`finance_tracker_testing\`.* TO '$MYSQL_USER'@'%';
    FLUSH PRIVILEGES;
EOSQL
