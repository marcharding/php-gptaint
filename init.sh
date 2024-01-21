# see https://stackoverflow.com/questions/5750450/how-can-i-print-each-command-before-executing
# set -o xtrace
trap 'echo -e "\e[0;32m" && echo -ne $(date "+%Y-%m-%d %H:%M:%S") && echo " >> Executing: $BASH_COMMAND" && echo -e "\e[0m"' DEBUG

mysql -h127.0.0.1 -uroot -e "CREATE DATABASE IF NOT EXISTS application"
mysql -h127.0.0.1 -uroot -e "CREATE USER IF NOT EXISTS 'application'@'localhost' IDENTIFIED BY 'application';"
mysql -h127.0.0.1 -uroot -e "GRANT ALL PRIVILEGES ON application.* TO 'application'@'localhost';"
mysql -h127.0.0.1 -uroot -e "GRANT ALL PRIVILEGES ON application.* TO 'application'@'127.0.0.1';"

mysql -h127.0.0.1 -uroot -e "CREATE DATABASE IF NOT EXISTS wordpress;"
mysql -h127.0.0.1 -uroot -e "CREATE USER IF NOT EXISTS 'wordpress'@'localhost' IDENTIFIED BY 'wordpress';"
mysql -h127.0.0.1 -uroot -e "GRANT ALL PRIVILEGES ON wordpress.* TO 'wordpress'@'localhost';"
mysql -h127.0.0.1 -uroot -e "GRANT ALL PRIVILEGES ON wordpress.* TO 'wordpress'@'127.0.0.1';"
mysql -h127.0.0.1 -uroot -e "FLUSH PRIVILEGES;"

trap - DEBUG
