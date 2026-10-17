#!/bin/bash
# Runs once, when the MySQL data volume is first initialised.
# Creates the separate database used by PHPUnit and grants it to the application user.
set -euo pipefail

mysql --protocol=socket -u root -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`air_quality_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`air_quality_test\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
