#!/bin/bash
# Runs once, when the database volume is first created. Lets the site user also create the scratch databases the test suite
# needs (NX_TEST_DB=nexora_test and the like), but nothing outside names starting with "nexora".
mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" -e "GRANT ALL PRIVILEGES ON \`nexora%\`.* TO '${MARIADB_USER}'@'%'; FLUSH PRIVILEGES;"
